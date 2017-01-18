<?php

/**
 * PHPUnit wrapper.
 */
class AllPhpUnitTestEngine extends ArcanistUnitTestEngine {

    private $configFile;
    private $phpunitBinary = 'phpunit';
    private $projectRoot;
    private $affectedFiles = array();

    public function run() {
        $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();
        $this->prepareConfigFile();

        $xmlConfig = simplexml_load_string(file_get_contents($this->configFile));
        $configFilterDirectory = $xmlConfig->xpath('/phpunit/filter/whitelist/directory');
        if(count($configFilterDirectory) != 0){
            foreach ($configFilterDirectory as $directory)
            {
                $this->affectedFiles = array_merge($this->affectedFiles, $this->getFilesFromXmlDirectory($directory));
            }
        }
        $selectedAffectedFiles = [];
        foreach ($this->getPaths() as $path)
        {
            $filePath = $this->projectRoot . '/' . $path;
            if(in_array($filePath, $this->affectedFiles))
                $selectedAffectedFiles[$filePath] = $filePath;
        }
        if(count($selectedAffectedFiles) != 0)
            $this->affectedFiles = $selectedAffectedFiles;

        $testFiles = array();
        $testsuites = $xmlConfig->xpath('/phpunit/testsuites/testsuite');
        foreach ($testsuites as $testsuite)
        {
            foreach ($testsuite->xpath('directory') as $directory)
            {
                $testFiles = array_merge($testFiles, $this->getFilesFromXmlDirectory($directory));
            }
        }

        if (empty($testFiles)) {
            throw new ArcanistNoEffectException(pht('No tests to run.'));
        }

        $this->prepareConfigFile();
        $futures = array();
        $tmpfiles = array();
        foreach ($testFiles as $class_path => $test_path) {
            $json_tmp = new TempFile();
            $clover_tmp = null;
            $clover = null;
            if ($this->getEnableCoverage() !== false) {
                $clover_tmp = new TempFile();
                $clover = csprintf('--coverage-clover %s', $clover_tmp);
            }

            $config = $this->configFile ? csprintf('-c %s', $this->configFile) : null;

            $stderr = '-d display_errors=stderr';
            $futures[$test_path] = new ExecFuture('%C %C %C --log-json %s %C %s',
                $this->phpunitBinary, $config, $stderr, $json_tmp, $clover, $test_path);
            $tmpfiles[$test_path] = array(
                'json' => $json_tmp,
                'clover' => $clover_tmp,
            );
        }

        $results = array();

        foreach ($futures as $test => $future) {
            list($err, $stdout, $stderr) = $future->resolve();
            $results[] = $this->parseTestResults(
                $test,
                $tmpfiles[$test]['json'],
                $tmpfiles[$test]['clover'],
                $stderr);
        }
        return array_mergev($results);
    }

    private function getFilesFromXmlDirectory($xmlData)
    {
        if(empty($xmlData['suffix'])) return array();
        $folderName = trim($xmlData);
        if($folderName[0] == '.')
            $folderName = substr($folderName,1, strlen($folderName) -1);
        $folderName = pathinfo($folderName)['basename'];
        return $this->getFiles($this->projectRoot . '/' . $folderName, (string)$xmlData['suffix']);
    }

    private function getFiles($folder, $suffix)
    {
        if(!is_dir($folder)) return [];
        $foundFiles = [];
        foreach (scandir($folder) as $value)
        {
            if(in_array($value, ['.', '..'])) continue;
            if(is_dir($folder. '/' . $value))
                $foundFiles = array_merge($foundFiles, $this->getFiles($folder. '/' . $value, $suffix));
            else
            {
                if(!Filesystem::pathExists($folder. '/' . $value)) continue;
                if ($this->endsWith($value, $suffix))
                    $foundFiles[$folder. '/' . $value] = $folder. '/' . $value;
            }
        }
        return $foundFiles;
    }

    private function endswith($string, $test) {
        $strlen = strlen($string);
        $testlen = strlen($test);
        if ($testlen > $strlen) return false;
        return substr_compare($string, $test, $strlen - $testlen, $testlen) === 0;
    }

    /**
     * Parse test results from phpunit json report.
     *
     * @param string $path Path to test
     * @param string $json_tmp Path to phpunit json report
     * @param string $clover_tmp Path to phpunit clover report
     * @param string $stderr Data written to stderr
     *
     * @return array
     */
    private function parseTestResults($path, $json_tmp, $clover_tmp, $stderr) {
        $test_results = Filesystem::readFile($json_tmp);
        return id(new ArcanistPhpunitTestResultParser())
            ->setEnableCoverage($this->getEnableCoverage())
            ->setProjectRoot($this->projectRoot)
            ->setCoverageFile($clover_tmp)
            ->setAffectedTests($this->affectedFiles)
            ->setStderr($stderr)
            ->parseTestResults($path, $test_results);
    }


    /**
     * Get places to look for PHP Unit tests that cover a given file. For some
     * file "/a/b/c/X.php", we look in the same directory:
     *
     *  /a/b/c/
     *
     * We then look in all parent directories for a directory named "tests/"
     * (or "Tests/"):
     *
     *  /a/b/c/tests/
     *  /a/b/tests/
     *  /a/tests/
     *  /tests/
     *
     * We also try to replace each directory component with "tests/":
     *
     *  /a/b/tests/
     *  /a/tests/c/
     *  /tests/b/c/
     *
     * We also try to add "tests/" at each directory level:
     *
     *  /a/b/c/tests/
     *  /a/b/tests/c/
     *  /a/tests/b/c/
     *  /tests/a/b/c/
     *
     * This finds tests with a layout like:
     *
     *  docs/
     *  src/
     *  tests/
     *
     * ...or similar. This list will be further pruned by the caller; it is
     * intentionally filesystem-agnostic to be unit testable.
     *
     * @param   string        PHP file to locate test cases for.
     * @return  list<string>  List of directories to search for tests in.
     */
    public static function getSearchLocationsForTests($path) {
        $file = basename($path);
        $dir  = dirname($path);

        $test_dir_names = array('tests', 'Tests');

        $try_directories = array();

        // Try in the current directory.
        $try_directories[] = array($dir);

        // Try in a tests/ directory anywhere in the ancestry.
        foreach (Filesystem::walkToRoot($dir) as $parent_dir) {
            if ($parent_dir == '/') {
                // We'll restore this later.
                $parent_dir = '';
            }
            foreach ($test_dir_names as $test_dir_name) {
                $try_directories[] = array($parent_dir, $test_dir_name);
            }
        }

        // Try replacing each directory component with 'tests/'.
        $parts = trim($dir, DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $parts);
        foreach (array_reverse(array_keys($parts)) as $key) {
            foreach ($test_dir_names as $test_dir_name) {
                $try = $parts;
                $try[$key] = $test_dir_name;
                array_unshift($try, '');
                $try_directories[] = $try;
            }
        }

        // Try adding 'tests/' at each level.
        foreach (array_reverse(array_keys($parts)) as $key) {
            foreach ($test_dir_names as $test_dir_name) {
                $try = $parts;
                $try[$key] = $test_dir_name.DIRECTORY_SEPARATOR.$try[$key];
                array_unshift($try, '');
                $try_directories[] = $try;
            }
        }

        $results = array();
        foreach ($try_directories as $parts) {
            $results[implode(DIRECTORY_SEPARATOR, $parts).DIRECTORY_SEPARATOR] = true;
        }

        return array_keys($results);
    }

    /**
     * Tries to find and update phpunit configuration file based on
     * `phpunit_config` option in `.arcconfig`.
     */
    private function prepareConfigFile() {
        $project_root = $this->projectRoot.DIRECTORY_SEPARATOR;
        $config = $this->getConfigurationManager()->getConfigFromAnySource(
            'phpunit_config');

        if ($config) {
            if (Filesystem::pathExists($project_root.$config)) {
                $this->configFile = $project_root.$config;
            } elseif ( Filesystem::pathExists($config)) {
                $this->configFile = $config;
            } else {
                throw new Exception(
                    pht(
                        'PHPUnit configuration file was not found in %s',
                        $project_root.$config));
            }
        }
        $bin = $this->getConfigurationManager()->getConfigFromAnySource(
            'unit.phpunit.binary');
        if ($bin) {
            if (Filesystem::binaryExists($bin)) {
                $this->phpunitBinary = $bin;
            } else {
                $this->phpunitBinary = Filesystem::resolvePath($bin, $project_root);
            }
        }
    }

}
