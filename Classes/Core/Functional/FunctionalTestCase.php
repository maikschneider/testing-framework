<?php

declare(strict_types=1);

namespace TYPO3\TestingFramework\Core\Functional;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\RiskyTestError;
use PHPUnit\Util\ErrorHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\ClassLoadingInformation;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Http\Application;
use TYPO3\TestingFramework\Core\BaseTestCase;
use TYPO3\TestingFramework\Core\DatabaseConnectionWrapper;
use TYPO3\TestingFramework\Core\Exception;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\DataSet;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Snapshot\DatabaseAccessor;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Snapshot\DatabaseSnapshot;
use TYPO3\TestingFramework\Core\Functional\Framework\FrameworkState;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;
use TYPO3\TestingFramework\Core\Testbase;

/**
 * Base test case class for functional tests, all TYPO3 CMS
 * functional tests should extend from this class!
 *
 * If functional tests need additional setUp() and tearDown() code,
 * they *must* call parent::setUp() and parent::tearDown() to properly
 * set up and destroy the test system.
 *
 * The functional test system creates a full new TYPO3 CMS instance
 * within typo3temp/ of the base system and the bootstraps this TYPO3 instance.
 * This abstract class takes care of creating this instance with its
 * folder structure and a LocalConfiguration, creates an own database
 * for each test run and imports tables of loaded extensions.
 *
 * Functional tests must be run standalone (calling native phpunit
 * directly) and can not be executed by eg. the ext:phpunit backend module.
 * Additionally, the script must be called from the document root
 * of the instance, otherwise path calculation is not successfully.
 *
 * Call whole functional test suite, example:
 * - cd /var/www/t3master/foo  # Document root of CMS instance, here is index.php of frontend
 * - typo3/../bin/phpunit -c components/testing_framework/core/Build/FunctionalTests.xml
 *
 * Call single test case, example:
 * - cd /var/www/t3master/foo  # Document root of CMS instance, here is index.php of frontend
 * - typo3/../bin/phpunit \
 *     --process-isolation \
 *     --bootstrap components/testing_framework/core/Build/FunctionalTestsBootstrap.php \
 *     typo3/sysext/core/Tests/Functional/DataHandling/DataHandlerTest.php
 */
abstract class FunctionalTestCase extends BaseTestCase implements ContainerInterface
{
    /**
     * An unique identifier for this test case. Location of the test
     * instance and database name depend on this. Calculated early in setUp()
     */
    protected string $identifier;

    /**
     * Absolute path to test instance document root. Depends on $identifier.
     * Calculated early in setUp()
     */
    protected string $instancePath;

    /**
     * Core extensions to load.
     *
     * If the test case needs additional core extensions as requirement,
     * they can be noted here and will be added to LocalConfiguration
     * extension list and ext_tables.sql of those extensions will be applied.
     *
     * This property will stay empty in this abstract, so it is possible
     * to just overwrite it in extending classes. Extensions noted here will
     * be loaded for every test of a test case and it is not possible to change
     * the list of loaded extensions between single tests of a test case.
     *
     * A default list of core extensions is always loaded.
     *
     * @see FunctionalTestCaseUtility $defaultActivatedCoreExtensions
     * @var array<int, string>
     */
    protected array $coreExtensionsToLoad = [];

    /**
     * Array of test/fixture extensions paths that should be loaded for a test.
     *
     * This property will stay empty in this abstract, so it is possible
     * to just overwrite it in extending classes. Extensions noted here will
     * be loaded for every test of a test case and it is not possible to change
     * the list of loaded extensions between single tests of a test case.
     *
     * Given path is expected to be relative to your document root, example:
     *
     * array(
     *   'typo3conf/ext/some_extension/Tests/Functional/Fixtures/Extensions/test_extension',
     *   'typo3conf/ext/base_extension',
     * );
     *
     * Extensions in this array are linked to the test instance, loaded
     * and their ext_tables.sql will be applied.
     *
     * @var string[]
     */
    protected array $testExtensionsToLoad = [];

    /**
     * Array of test/fixture folder or file paths that should be linked for a test.
     *
     * This property will stay empty in this abstract, so it is possible
     * to just overwrite it in extending classes. Path noted here will
     * be linked for every test of a test case and it is not possible to change
     * the list of folders between single tests of a test case.
     *
     * array(
     *   'link-source' => 'link-destination'
     * );
     *
     * Given paths are expected to be relative to the test instance root.
     * The array keys are the source paths and the array values are the destination
     * paths, example:
     *
     * [
     *   'typo3/sysext/impext/Tests/Functional/Fixtures/Folders/fileadmin/user_upload' =>
     *   'fileadmin/user_upload',
     * ]
     *
     * To be able to link from my_own_ext the extension path needs also to be registered in
     * property $testExtensionsToLoad
     *
     * @var string[]
     */
    protected array $pathsToLinkInTestInstance = [];

    /**
     * Similar to $pathsToLinkInTestInstance, with the difference that given
     * paths are really duplicated and provided in the instance - instead of
     * using symbolic links. Examples:
     *
     * [
     *   // Copy an entire directory recursive to fileadmin
     *   'typo3/sysext/lowlevel/Tests/Functional/Fixtures/testImages/' => 'fileadmin/',
     *   // Copy a single file into some deep destination directory
     *   'typo3/sysext/lowlevel/Tests/Functional/Fixtures/testImage/someImage.jpg' => 'fileadmin/_processed_/0/a/someImage.jpg',
     * ]
     *
     * @var string[]
     */
    protected array $pathsToProvideInTestInstance = [];

    /**
     * This configuration array is merged with TYPO3_CONF_VARS
     * that are set in default configuration and factory configuration
     */
    protected array $configurationToUseInTestInstance = [];

    /**
     * Array of folders that should be created inside the test instance document root.
     *
     * This property will stay empty in this abstract, so it is possible
     * to just overwrite it in extending classes. Path noted here will
     * be linked for every test of a test case and it is not possible to change
     * the list of folders between single tests of a test case.
     *
     * Per default the following folder are created
     * /fileadmin
     * /typo3temp
     * /typo3conf
     * /typo3conf/ext
     *
     * To create additional folders add the paths to this array. Given paths are expected to be
     * relative to the test instance root and have to begin with a slash. Example:
     *
     * [
     *   'fileadmin/user_upload'
     * ]
     */
    protected array $additionalFoldersToCreate = [];

    /**
     * The fixture which is used when initializing a backend user
     */
    protected string $backendUserFixture = 'PACKAGE:typo3/testing-framework/Resources/Core/Functional/Fixtures/be_users.xml';

    /**
     * Some functional test cases do not need a fully set up database with all tables and fields.
     * Those tests should set this property to false, which will skip database creation
     * in setUp(). This significantly speeds up functional test execution and should be done
     * if possible.
     */
    protected bool $initializeDatabase = true;

    private ContainerInterface $container;

    /**
     * These two internal variable track if the given test is the first test of
     * that test case. This variable is set to current calling test case class.
     * Consecutive tests then optimize and do not create a full
     * database structure again but instead just truncate all tables which
     * is much quicker.
     */
    private static string $currestTestCaseClass = '';
    private bool $isFirstTest = true;

    /**
     * Set up creates a test instance and database.
     *
     * This method should be called with parent::setUp() in your test cases!
     *
     * @throws DBALException
     */
    protected function setUp(): void
    {
        if (!defined('ORIGINAL_ROOT')) {
            self::markTestSkipped('Functional tests must be called through phpunit on CLI');
        }

        $this->identifier = self::getInstanceIdentifier();
        $this->instancePath = self::getInstancePath();
        putenv('TYPO3_PATH_ROOT=' . $this->instancePath);
        putenv('TYPO3_PATH_APP=' . $this->instancePath);

        $testbase = new Testbase();
        $testbase->setTypo3TestingContext();

        // See if we're the first test of this test case.
        $currentTestCaseClass = get_called_class();
        if (self::$currestTestCaseClass !== $currentTestCaseClass) {
            self::$currestTestCaseClass = $currentTestCaseClass;
        } else {
            $this->isFirstTest = false;
        }

        // sqlite db path preparation
        $dbPathSqlite = dirname($this->instancePath) . '/functional-sqlite-dbs/test_' . $this->identifier . '.sqlite';
        $dbPathSqliteEmpty = dirname($this->instancePath) . '/functional-sqlite-dbs/test_' . $this->identifier . '.empty.sqlite';

        if (!$this->isFirstTest) {
            // Reusing an existing instance. This typically happens for the second, third, ... test
            // in a test case, so environment is set up only once per test case.
            GeneralUtility::purgeInstances();
            $this->container = $testbase->setUpBasicTypo3Bootstrap($this->instancePath);
            if ($this->initializeDatabase) {
                $testbase->initializeTestDatabaseAndTruncateTables($dbPathSqlite, $dbPathSqliteEmpty);
            }
            $testbase->loadExtensionTables();
        } else {
            DatabaseSnapshot::initialize(dirname($this->getInstancePath()) . '/functional-sqlite-dbs/', $this->identifier);
            $testbase->removeOldInstanceIfExists($this->instancePath);
            // Basic instance directory structure
            $testbase->createDirectory($this->instancePath . '/fileadmin');
            $testbase->createDirectory($this->instancePath . '/typo3temp/var/transient');
            $testbase->createDirectory($this->instancePath . '/typo3temp/assets');
            $testbase->createDirectory($this->instancePath . '/typo3conf/ext');
            // Additionally requested directories
            foreach ($this->additionalFoldersToCreate as $directory) {
                $testbase->createDirectory($this->instancePath . '/' . $directory);
            }
            $testbase->setUpInstanceCoreLinks($this->instancePath);
            $testbase->linkTestExtensionsToInstance($this->instancePath, $this->testExtensionsToLoad);
            $testbase->linkFrameworkExtensionsToInstance($this->instancePath, [
                'Resources/Core/Functional/Extensions/json_response',
                'Resources/Core/Functional/Extensions/private_container',
            ]);
            $testbase->linkPathsInTestInstance($this->instancePath, $this->pathsToLinkInTestInstance);
            $testbase->providePathsInTestInstance($this->instancePath, $this->pathsToProvideInTestInstance);
            $localConfiguration['DB'] = $testbase->getOriginalDatabaseSettingsFromEnvironmentOrLocalConfiguration();

            $originalDatabaseName = '';
            $dbName = '';
            $dbDriver = $localConfiguration['DB']['Connections']['Default']['driver'];
            if ($dbDriver !== 'pdo_sqlite') {
                $originalDatabaseName = $localConfiguration['DB']['Connections']['Default']['dbname'];
                // Append the unique identifier to the base database name to end up with a single database per test case
                $dbName = $originalDatabaseName . '_ft' . $this->identifier;
                $localConfiguration['DB']['Connections']['Default']['dbname'] = $dbName;
                $localConfiguration['DB']['Connections']['Default']['wrapperClass'] = DatabaseConnectionWrapper::class;
                $testbase->testDatabaseNameIsNotTooLong($originalDatabaseName, $localConfiguration);
                if ($dbDriver === 'mysqli' || $dbDriver === 'pdo_mysql') {
                    $localConfiguration['DB']['Connections']['Default']['charset'] = 'utf8mb4';
                    $localConfiguration['DB']['Connections']['Default']['tableoptions']['charset'] = 'utf8mb4';
                    $localConfiguration['DB']['Connections']['Default']['tableoptions']['collate'] = 'utf8mb4_unicode_ci';
                    $localConfiguration['DB']['Connections']['Default']['initCommands'] = 'SET SESSION sql_mode = \'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY\';';
                }
            } else {
                // sqlite dbs of all tests are stored in a dir parallel to instance roots. Allows defining this path as tmpfs.
                $testbase->createDirectory(dirname($this->instancePath) . '/functional-sqlite-dbs');
                $localConfiguration['DB']['Connections']['Default']['path'] = $dbPathSqlite;
            }

            // Set some hard coded base settings for the instance. Those could be overruled by
            // $this->configurationToUseInTestInstance if needed again.
            $localConfiguration['SYS']['displayErrors'] = '1';
            $localConfiguration['SYS']['debugExceptionHandler'] = '';
            // By setting errorHandler to empty string, only the phpunit error handler is
            // registered in functional tests, so settings like convertWarningsToExceptions="true"
            // in FunctionalTests.xml will let tests fail that throw warnings.
            $localConfiguration['SYS']['errorHandler'] = '';
            $localConfiguration['SYS']['trustedHostsPattern'] = '.*';
            $localConfiguration['SYS']['encryptionKey'] = 'i-am-not-a-secure-encryption-key';
            $localConfiguration['GFX']['processor'] = 'GraphicsMagick';
            // Set cache backends to null backend instead of database backend let us save time for creating
            // database schema for it and reduces selects/inserts to the database for cache operations, which
            // are generally not really needed for functional tests. Specific tests may restore this in if needed.
            $localConfiguration['SYS']['caching']['cacheConfigurations']['hash']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $localConfiguration['SYS']['caching']['cacheConfigurations']['imagesizes']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $localConfiguration['SYS']['caching']['cacheConfigurations']['pages']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $localConfiguration['SYS']['caching']['cacheConfigurations']['pagesection']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $localConfiguration['SYS']['caching']['cacheConfigurations']['rootline']['backend'] = 'TYPO3\\CMS\\Core\\Cache\\Backend\\NullBackend';
            $testbase->setUpLocalConfiguration($this->instancePath, $localConfiguration, $this->configurationToUseInTestInstance);
            $defaultCoreExtensionsToLoad = [
                'core',
                'backend',
                'frontend',
                'extbase',
                'install',
                'recordlist',
                'fluid',
            ];
            $testbase->setUpPackageStates(
                $this->instancePath,
                $defaultCoreExtensionsToLoad,
                $this->coreExtensionsToLoad,
                $this->testExtensionsToLoad,
                [
                    'Resources/Core/Functional/Extensions/json_response',
                    'Resources/Core/Functional/Extensions/private_container',
                ]
            );
            $this->container = $testbase->setUpBasicTypo3Bootstrap($this->instancePath);
            if ($this->initializeDatabase) {
                if ($dbDriver !== 'pdo_sqlite') {
                    $testbase->setUpTestDatabase($dbName, $originalDatabaseName);
                } else {
                    $testbase->setUpTestDatabase($dbPathSqlite, $originalDatabaseName);
                }
            }
            $testbase->loadExtensionTables();
            if ($this->initializeDatabase) {
                $testbase->createDatabaseStructure();
                if ($dbDriver === 'pdo_sqlite') {
                    // Copy sqlite file '/path/functional-sqlite-dbs/test_123.sqlite' to
                    // '/path/functional-sqlite-dbs/test_123.empty.sqlite'. This is re-used for consequtive tests.
                    copy($dbPathSqlite, $dbPathSqliteEmpty);
                }
            }
        }
    }

    /**
     * Default tearDown() unsets local variables to safe memory in phpunit test runner
     */
    protected function tearDown(): void
    {
        // Unset especially the container after each test, it is a huge memory hog.
        // Test class instances in phpunit are kept until end of run, this sums up.
        unset($this->container);
        unset($this->identifier, $this->instancePath, $this->coreExtensionsToLoad);
        unset($this->testExtensionsToLoad, $this->pathsToLinkInTestInstance);
        unset($this->pathsToProvideInTestInstance, $this->configurationToUseInTestInstance);
        unset($this->additionalFoldersToCreate, $this->backendUserFixture);

        // Verify no dangling error handler is registered. This might happen when
        // tests register an own error handler which is not reset again. This error
        // handler then may "eat" error of subsequent tests.
        // Register a dummy error handler to retrieve *previous* one and unregister dummy again,
        // then verify previous is the phpunit error handler. This will mark the one test that
        // fails to unset/restore it's custom error handler as "risky".
        // @todo: Consider moving this to BaseTestCase to have it for unit tests, too.
        // @see: https://github.com/sebastianbergmann/phpunit/issues/4801
        $previousErrorHandler = set_error_handler(function (int $errorNumber, string $errorString, string $errorFile, int $errorLine): bool {return false;});
        restore_error_handler();
        if (!$previousErrorHandler instanceof ErrorHandler) {
            throw new RiskyTestError(
                'tearDown() check: A dangling error handler has been found. Use restore_error_handler() to unset it.',
                1634490417
            );
        }

        // Verify no dangling exception handler is registered. Same scenario as with error handlers.
        // @todo: Consider moving this to BaseTestCase to have it for unit tests, too.
        $previousExceptionHandler = set_exception_handler(function () {});
        restore_exception_handler();
        if ($previousExceptionHandler !== null) {
            throw new RiskyTestError(
                'tearDown() check: A dangling exception handler has been found. Use restore_exception_handler() to unset it.',
                1634490418
            );
        }
    }

    protected function getConnectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Returns the default TYPO3 dependency injection container
     * containing all public services.
     *
     * May be used if a class is instantiated that requires
     * the default container as argument.
     */
    protected function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    private function getPrivateContainer(): ContainerInterface
    {
        return $this->getContainer()->get('typo3.testing-framework.private-container');
    }

    /**
     * Implements ContainerInterface. Can be used by tests to get both public
     * and non-public services.
     */
    public function get(string $id): mixed
    {
        if ($this->getContainer()->has($id)) {
            return $this->getContainer()->get($id);
        }
        return $this->getPrivateContainer()->get($id);
    }

    /**
     * Implements ContainerInterface. Used to find out if there is such a service.
     * This will return true if the service is public OR non-public
     * (non-public = injected into at least one public service).
     */
    public function has(string $id): bool
    {
        return $this->getContainer()->has($id) || $this->getPrivateContainer()->has($id);
    }

    /**
     * Initialize backend user.
     *
     * @param int $userUid uid of the user we want to initialize. This user must exist in the fixture file.
     */
    protected function setUpBackendUserFromFixture(int $userUid): BackendUserAuthentication
    {
        $this->importDataSet($this->backendUserFixture);
        return $this->setUpBackendUser($userUid);
    }

    /**
     * Sets up Backend User which is already available in db
     */
    protected function setUpBackendUser(int $userUid): BackendUserAuthentication
    {
        $userRow = $this->getBackendUserRecordFromDatabase($userUid);
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $session = $backendUser->createUserSession($userRow);
        $sessionId = $session->getIdentifier();
        $request = $this->createServerRequest('https://typo3-testing.local/typo3/');
        $request = $request->withCookieParams(['be_typo_user' => $sessionId]);
        $backendUser = $this->authenticateBackendUser($backendUser, $request);
        // @todo: remove this with the next major version
        $GLOBALS['BE_USER'] = $backendUser;
        return $backendUser;
    }

    protected function getBackendUserRecordFromDatabase(int $userId): ?array
    {
        $queryBuilder = $this->getConnectionPool()
            ->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId, \PDO::PARAM_INT)))
            ->executeQuery();
        return $result->fetchAssociative() ?: null;
    }

    private function createServerRequest(string $url, string $method = 'GET'): ServerRequestInterface
    {
        $requestUrlParts = parse_url($url);
        $docRoot = $this->instancePath;
        $serverParams = [
            'DOCUMENT_ROOT' => $docRoot,
            'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request',
            'HTTP_HOST' => $requestUrlParts['host'] ?? 'localhost',
            'SERVER_NAME' => $requestUrlParts['host'] ?? 'localhost',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'PHP_SELF' => '/typo3/index.php',
            'SCRIPT_FILENAME' => $docRoot . '/index.php',
            'PATH_TRANSLATED' => $docRoot . '/index.php',
            'QUERY_STRING' => $requestUrlParts['query'] ?? '',
            'REQUEST_URI' => $requestUrlParts['path'] . (isset($requestUrlParts['query']) ? '?' . $requestUrlParts['query'] : ''),
            'REQUEST_METHOD' => $method,
        ];
        // Define HTTPS and server port
        if (isset($requestUrlParts['scheme'])) {
            if ($requestUrlParts['scheme'] === 'https') {
                $serverParams['HTTPS'] = 'on';
                $serverParams['SERVER_PORT'] = '443';
            } else {
                $serverParams['SERVER_PORT'] = '80';
            }
        }

        // Define a port if used in the URL
        if (isset($requestUrlParts['port'])) {
            $serverParams['SERVER_PORT'] = $requestUrlParts['port'];
        }
        // set up normalizedParams
        $request = new ServerRequest($url, $method, null, [], $serverParams);
        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    protected function authenticateBackendUser(BackendUserAuthentication $backendUser, ServerRequestInterface $request): BackendUserAuthentication
    {
        $backendUser->start($request);
        if (!is_array($backendUser->user) || !$backendUser->user['uid']) {
            throw new Exception(
                'Can not initialize backend user',
                1377095807
            );
        }
        $backendUser->backendCheckLogin();
        GeneralUtility::makeInstance(Context::class)->setAspect(
            'backend.user',
            GeneralUtility::makeInstance(UserAspect::class, $backendUser)
        );
        return $backendUser;
    }

    /**
     * Imports a data set represented as XML into the test database,
     *
     * @param string $path Absolute path to the XML file containing the data set to load
     * @throws Exception
     */
    protected function importDataSet(string $path): void
    {
        $testbase = new Testbase();
        $testbase->importXmlDatabaseFixture($path);
    }

    /**
     * Import data from a CSV file to database
     * Single file can contain data from multiple tables
     *
     * @param string $path Absolute path to the CSV file containing the data set to load
     */
    public function importCSVDataSet(string $path): void
    {
        $dataSet = DataSet::read($path, true);

        foreach ($dataSet->getTableNames() as $tableName) {
            $connection = $this->getConnectionPool()->getConnectionForTable($tableName);
            foreach ($dataSet->getElements($tableName) as $element) {
                try {
                    // With mssql, hard setting uid auto-increment primary keys is only allowed if
                    // the table is prepared for such an operation beforehand
                    $platform = $connection->getDatabasePlatform();
                    $sqlServerIdentityDisabled = false;
                    if ($platform instanceof SQLServerPlatform) {
                        try {
                            $connection->executeStatement('SET IDENTITY_INSERT ' . $tableName . ' ON');
                            $sqlServerIdentityDisabled = true;
                        } catch (DBALException $e) {
                            // Some tables like sys_refindex don't have an auto-increment uid field and thus no
                            // IDENTITY column. Instead of testing existance, we just try to set IDENTITY ON
                            // and catch the possible error that occurs.
                        }
                    }

                    // Some DBMS like mssql are picky about inserting blob types with correct cast, setting
                    // types correctly (like Connection::PARAM_LOB) allows doctrine to create valid SQL
                    $types = [];
                    $tableDetails = $connection->createSchemaManager()->listTableDetails($tableName);
                    foreach ($element as $columnName => $columnValue) {
                        $types[] = $tableDetails->getColumn($columnName)->getType()->getBindingType();
                    }

                    // Insert the row
                    $connection->insert($tableName, $element, $types);

                    if ($sqlServerIdentityDisabled) {
                        // Reset identity if it has been changed
                        $connection->executeStatement('SET IDENTITY_INSERT ' . $tableName . ' OFF');
                    }
                } catch (DBALException $e) {
                    self::fail('SQL Error for table "' . $tableName . '": ' . LF . $e->getMessage());
                }
            }
            Testbase::resetTableSequences($connection, $tableName);
        }
    }

    /**
     * Compare data in database with a CSV file
     *
     * @param string $fileName Absolute path to the CSV file
     */
    protected function assertCSVDataSet(string $fileName): void
    {
        if (!PathUtility::isAbsolutePath($fileName)) {
            // @deprecated: Always feed absolute paths.
            $fileName = GeneralUtility::getFileAbsFileName($fileName);
        }

        $dataSet = DataSet::read($fileName);
        $failMessages = [];

        foreach ($dataSet->getTableNames() as $tableName) {
            $hasUidField = ($dataSet->getIdIndex($tableName) !== null);
            $hasHashField = ($dataSet->getHashIndex($tableName) !== null);
            $records = $this->getAllRecords($tableName, $hasUidField, $hasHashField);
            $assertions = (array)$dataSet->getElements($tableName);
            foreach ($assertions as $assertion) {
                $result = $this->assertInRecords($assertion, $records);
                if ($result === false) {
                    if ($hasUidField && empty($records[$assertion['uid']])) {
                        $failMessages[] = 'Record "' . $tableName . ':' . $assertion['uid'] . '" not found in database';
                        continue;
                    }
                    if ($hasHashField && empty($records[$assertion['hash']])) {
                        $failMessages[] = 'Record "' . $tableName . ':' . $assertion['hash'] . '" not found in database';
                        continue;
                    }
                    if ($hasUidField) {
                        $recordIdentifier = $tableName . ':' . $assertion['uid'];
                        $additionalInformation = $this->renderRecords($assertion, $records[$assertion['uid']]);
                    } elseif ($hasHashField) {
                        $recordIdentifier = $tableName . ':' . $assertion['hash'];
                        $additionalInformation = $this->renderRecords($assertion, $records[$assertion['hash']]);
                    } else {
                        $recordIdentifier = $tableName;
                        $additionalInformation = $this->arrayToString($assertion);
                    }
                    $failMessages[] = 'Assertion in data-set failed for "' . $recordIdentifier . '":' . LF . $additionalInformation;
                    // Unset failed asserted record
                    if ($hasUidField) {
                        unset($records[$assertion['uid']]);
                    }
                    if ($hasHashField) {
                        unset($records[$assertion['hash']]);
                    }
                } else {
                    // Unset asserted record
                    unset($records[$result]);
                    // Increase assertion counter
                    self::assertTrue($result !== false);
                }
            }
            if (!empty($records)) {
                foreach ($records as $record) {
                    $emptyAssertion = array_fill_keys($dataSet->getFields($tableName), '[none]');
                    $reducedRecord = array_intersect_key($record, $emptyAssertion);
                    if ($hasUidField) {
                        $recordIdentifier = $tableName . ':' . $record['uid'];
                        $additionalInformation = $this->renderRecords($emptyAssertion, $reducedRecord);
                    } elseif ($hasHashField) {
                        $recordIdentifier = $tableName . ':' . $record['hash'];
                        $additionalInformation = $this->renderRecords($emptyAssertion, $reducedRecord);
                    } else {
                        $recordIdentifier = $tableName;
                        $additionalInformation = $this->arrayToString($reducedRecord);
                    }
                    $failMessages[] = 'Not asserted record found for "' . $recordIdentifier . '":' . LF . $additionalInformation;
                }
            }
        }

        if (!empty($failMessages)) {
            self::fail(implode(LF, $failMessages));
        }
    }

    /**
     * Check if $expectedRecord is present in $actualRecords array
     * and compares if all column values from matches
     *
     * @param array $expectedRecord
     * @param array $actualRecords
     * @return bool|int|string false if record is not found or some column value doesn't match
     */
    protected function assertInRecords(array $expectedRecord, array $actualRecords)
    {
        foreach ($actualRecords as $index => $record) {
            $differentFields = $this->getDifferentFields($expectedRecord, $record);

            if (empty($differentFields)) {
                return $index;
            }
        }

        return false;
    }

    /**
     * Fetches all records from a database table
     * Helper method for assertCSVDataSet
     */
    protected function getAllRecords(string $tableName, bool $hasUidField = false, bool $hasHashField = false): array
    {
        $queryBuilder = $this->getConnectionPool()
            ->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();
        $statement = $queryBuilder
            ->select('*')
            ->from($tableName)
            ->executeQuery();

        if (!$hasUidField && !$hasHashField) {
            return $statement->fetchAllAssociative();
        }

        if ($hasUidField) {
            $allRecords = [];
            while ($record = $statement->fetchAssociative()) {
                $index = $record['uid'];
                $allRecords[$index] = $record;
            }
        } else {
            $allRecords = [];
            while ($record = $statement->fetchAssociative()) {
                $index = $record['hash'];
                $allRecords[$index] = $record;
            }
        }

        return $allRecords;
    }

    /**
     * Format array as human readable string. Used to format verbose error messages in assertCSVDataSet
     */
    protected function arrayToString(array $array): string
    {
        $elements = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = $this->arrayToString($value);
            }
            $elements[] = "'" . $key . "' => '" . $value . "'";
        }
        return 'array(' . PHP_EOL . '   ' . implode(', ' . PHP_EOL . '   ', $elements) . PHP_EOL . ')' . PHP_EOL;
    }

    /**
     * Format output showing difference between expected and actual db row in a human readable way
     * Used to format verbose error messages in assertCSVDataSet
     */
    protected function renderRecords(array $assertion, array $record): string
    {
        $differentFields = $this->getDifferentFields($assertion, $record);
        $columns = [
            'fields' => ['Fields'],
            'assertion' => ['Assertion'],
            'record' => ['Record'],
        ];
        $lines = [];
        $linesFromXmlValues = [];
        $result = '';

        foreach ($differentFields as $differentField) {
            $columns['fields'][] = $differentField;
            $columns['assertion'][] = ($assertion[$differentField] === null ? 'NULL' : $assertion[$differentField]);
            $columns['record'][] = ($record[$differentField] === null ? 'NULL' : $record[$differentField]);
        }

        foreach ($columns as $columnIndex => $column) {
            $columnLength = null;
            foreach ($column as $value) {
                if (strpos((string)$value, '<?xml') === 0) {
                    $value = '[see diff]';
                }
                $valueLength = strlen((string)$value);
                if (empty($columnLength) || $valueLength > $columnLength) {
                    $columnLength = $valueLength;
                }
            }
            foreach ($column as $valueIndex => $value) {
                if (strpos((string)$value, '<?xml') === 0) {
                    if ($columnIndex === 'assertion') {
                        try {
                            self::assertXmlStringEqualsXmlString((string)$value, (string)$record[$columns['fields'][$valueIndex]]);
                        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
                            $linesFromXmlValues[] = 'Diff for field "' . $columns['fields'][$valueIndex] . '":' . PHP_EOL .
                                $e->getComparisonFailure()->getDiff();
                        }
                    }
                    $value = '[see diff]';
                }
                $lines[$valueIndex][$columnIndex] = str_pad((string)$value, $columnLength, ' ');
            }
        }

        foreach ($lines as $line) {
            $result .= implode('|', $line) . PHP_EOL;
        }

        foreach ($linesFromXmlValues as $lineFromXmlValues) {
            $result .= PHP_EOL . $lineFromXmlValues . PHP_EOL;
        }

        return $result;
    }

    /**
     * Compares two arrays containing db rows and returns array containing column names which don't match
     * It's a helper method used in assertCSVDataSet
     */
    protected function getDifferentFields(array $assertion, array $record): array
    {
        $differentFields = [];

        foreach ($assertion as $field => $value) {
            if (strpos((string)$value, '\\*') === 0) {
                continue;
            }

            if (!array_key_exists($field, $record)) {
                throw new \ValueError(sprintf('"%s" column not found in the input data.', $field));
            }

            if (strpos((string)$value, '<?xml') === 0) {
                try {
                    self::assertXmlStringEqualsXmlString((string)$value, (string)$record[$field]);
                } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
                    $differentFields[] = $field;
                }
            } elseif ($value === null && $record[$field] !== $value) {
                $differentFields[] = $field;
            } elseif ((string)$record[$field] !== (string)$value) {
                $differentFields[] = $field;
            }
        }

        return $differentFields;
    }

    /**
     * Sets up a root-page containing TypoScript settings for frontend testing.
     *
     * Parameter `$typoScriptFiles` can either be
     * + `[
     *      'path/first.typoscript',
     *      'path/second.typoscript'
     *    ]`
     *   which just loads files to the setup setion of the TypoScript template
     *   record (legacy behavior of this method)
     * + `[
     *      'constants' => ['path/constants.typoscript'],
     *      'setup' => ['path/setup.typoscript']
     *    ]`
     *   which allows to define contents for the `contants` and `setup` part
     *   of the TypoScript template record at the same time
     */
    protected function setUpFrontendRootPage(int $pageId, array $typoScriptFiles = [], array $templateValues = []): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $page = $connection->select(['*'], 'pages', ['uid' => $pageId])->fetchAssociative();

        if (empty($page)) {
            self::fail('Cannot set up frontend root page "' . $pageId . '"');
        }

        // migrate legacy definition to support `constants` and `setup`
        if (!empty($typoScriptFiles)
            && empty($typoScriptFiles['constants'])
            && empty($typoScriptFiles['setup'])
        ) {
            $typoScriptFiles = ['setup' => $typoScriptFiles];
        }

        $databasePlatform = 'mysql';
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $databasePlatform = 'postgresql';
        }

        $connection->update(
            'pages',
            ['is_siteroot' => 1],
            ['uid' => $pageId]
        );

        $templateFields = array_merge(
            [
                'title' => '',
                'constants' => '',
                'config' => '',
            ],
            $templateValues,
            [
                'pid' => $pageId,
                'clear' => 3,
                'root' => 1,
            ]
        );

        foreach ($typoScriptFiles['constants'] ?? [] as $typoScriptFile) {
            $templateFields['constants'] .= '<INCLUDE_TYPOSCRIPT: source="FILE:' . $typoScriptFile . '">' . LF;
        }
        $templateFields['constants'] .= 'databasePlatform = ' . $databasePlatform . LF;
        foreach ($typoScriptFiles['setup'] ?? [] as $typoScriptFile) {
            $templateFields['config'] .= '<INCLUDE_TYPOSCRIPT: source="FILE:' . $typoScriptFile . '">' . LF;
        }

        $connection = $this->getConnectionPool()
            ->getConnectionForTable('sys_template');
        $connection->delete('sys_template', ['pid' => $pageId]);
        $connection->insert(
            'sys_template',
            $templateFields
        );
    }

    /**
     * Adds TypoScript setup snippet to the existing template record
     */
    protected function addTypoScriptToTemplateRecord(int $pageId, string $typoScript): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_template');
        $template = $connection->select(['*'], 'sys_template', ['pid' => $pageId, 'root' => 1])->fetchAssociative();

        if (empty($template)) {
            self::fail('Cannot find root template on page with id: "' . $pageId . '"');
        }
        $updateFields['config'] = $template['config'] . LF . $typoScript;
        $connection->update(
            'sys_template',
            $updateFields,
            ['uid' => $template['uid']]
        );
    }

    /**
     * Execute a TYPO3 frontend application request.
     *
     * @param InternalRequest $request
     * @param InternalRequestContext|null $context
     * @param bool $followRedirects Whether to follow HTTP location redirects
     */
    protected function executeFrontendSubRequest(
        InternalRequest $request,
        InternalRequestContext $context = null,
        bool $followRedirects = false
    ): ResponseInterface {
        if ($context === null) {
            $context = new InternalRequestContext();
        }
        $locationHeaders = [];
        do {
            $response = $this->retrieveFrontendSubRequestResult($request, $context);
            $locationHeader = $response->getHeaderLine('location');
            if (in_array($locationHeader, $locationHeaders, true)) {
                self::fail(
                    implode(LF . '* ', array_merge(
                        ['Redirect loop detected:'],
                        $locationHeaders,
                        [$locationHeader]
                    ))
                );
            }
            $locationHeaders[] = $locationHeader;
            $request = new InternalRequest($locationHeader);
        } while ($followRedirects && !empty($locationHeader));
        return $response;
    }

    /**
     * The internal worker method that actually fires the frontend application request.
     * The method is still a bit messy and needs to do some stuff that can be obsoleted
     * when the core becomes more clean.
     * It's main job is to turn the testing-framework internal request object into a
     * a PSR-7 core/Http/ServerRequest, register the testing-framework InternalRequestContext
     * object for the testing-framework ext:json_response middlewares to operate on, and
     * to then call the ext:frontend Application.
     * Note this method is in 'early' state and will change over time.
     *
     * @internal Do not use directly, use ->executeFrontendSubRequest() instead
     */
    private function retrieveFrontendSubRequestResult(
        InternalRequest $request,
        InternalRequestContext $context
    ): ResponseInterface {
        FrameworkState::push();
        FrameworkState::reset();

        // Re-init Environment $currentScript: Entry point to FE calls is /index.php, not /typo3/index.php
        // see also \TYPO3\TestingFramework\Core\SystemEnvironmentBuilder
        Environment::initialize(
            Environment::getContext(),
            Environment::isCli(),
            false,
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getPublicPath() . '/index.php',
            Environment::isWindows() ? 'WINDOWS' : 'UNIX'
        );

        // Needed for GeneralUtility::getIndpEnv('SCRIPT_NAME') to return correct value
        // instead of 'vendor/phpunit/phpunit/phpunit', used eg. in TypoScriptFrontendController absRefPrefix='auto'
        // See second data provider of UriPrefixRenderingTest
        // @todo: Make TSFE not use getIndpEnv() anymore
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $requestUrlParts = parse_url((string)$request->getUri());
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = $requestUrlParts['host'] ?? 'localhost';

        $container = Bootstrap::init(ClassLoadingInformation::getClassLoader());

        // The testing-framework registers extension 'json_response' that brings some middlewares which
        // allow to eg. log in backend users in frontend application context. These globals are used to
        // carry that information.
        $_SERVER['X_TYPO3_TESTING_FRAMEWORK']['request'] = $request;

        // Create ServerRequest from testing-framework InternalRequest object
        $uri = $request->getUri();

        // Build minimal serverParams and hand over to ServerRequest. The normalizedParams
        // attribute relies on these. Note the access to $_SERVER should be dropped when the
        // above getIndpEnv() can be dropped, too.
        $serverParams = [
            'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'],
            'HTTP_HOST'  => $_SERVER['HTTP_HOST'],
            'SERVER_NAME' => $_SERVER['SERVER_NAME'],
            'HTTPS' => $uri->getScheme() === 'https' ? 'on' : 'off',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        $serverRequest = new ServerRequest(
            $uri,
            $request->getMethod(),
            $request->getBody(),
            $request->getHeaders(),
            $serverParams
        );
        if ($parsedBody = $request->getParsedBody()) {
            $serverRequest = $serverRequest->withParsedBody($parsedBody);
        }
        $serverRequest = $serverRequest->withAttribute('typo3.testing.context', $context);
        $requestUrlParts = [];
        parse_str($uri->getQuery(), $requestUrlParts);
        $serverRequest = $serverRequest->withQueryParams($requestUrlParts);
        try {
            $frontendApplication = $container->get(Application::class);
            $response = $frontendApplication->handle($serverRequest);
        } catch (\Exception $exception) {
            // When a FE call throws an exception, locks are released in any case to prevent a deadlock.
            // @todo: This code may become obsolete, when a __destruct() of TSFE handles release AND
            //        TSFE instances *always* shut down after use.
            if (isset($GLOBALS['TSFE']) && $GLOBALS['TSFE'] instanceof TypoScriptFrontendController) {
                $GLOBALS['TSFE']->releaseLocks();
            }
            throw $exception;
        } finally {
            // Somewhere an ob_start() is called in frontend that is not cleaned. Work around that for now.
            ob_end_clean();

            FrameworkState::pop();

            // Reset Environment $currentScript: Entry point is /typo3/index.php again.
            // see also \TYPO3\TestingFramework\Core\SystemEnvironmentBuilder
            Environment::initialize(
                Environment::getContext(),
                Environment::isCli(),
                false,
                Environment::getProjectPath(),
                Environment::getPublicPath(),
                Environment::getVarPath(),
                Environment::getConfigPath(),
                Environment::getPublicPath() . '/typo3/index.php',
                Environment::isWindows() ? 'WINDOWS' : 'UNIX'
            );
        }
        return $response;
    }

    /**
     * Whether to allow modification of IDENTITY_INSERT for SQL Server platform.
     * + null: unspecified, decided later during runtime (based on 'uid' & $TCA)
     * + true: always allow, e.g. before actually importing data
     * + false: always deny, e.g. when importing data is finished
     *
     * @throws DBALException
     * @todo: Seems to be unused. Deprecate in v6 and remove here?
     */
    protected function allowIdentityInsert(?bool $allowIdentityInsert): void
    {
        $connection = $this->getConnectionPool()->getConnectionByName(
            ConnectionPool::DEFAULT_CONNECTION_NAME
        );
        if (!$connection instanceof DatabaseConnectionWrapper) {
            return;
        }
        $connection->allowIdentityInsert($allowIdentityInsert);
    }

    /**
     * Invokes a database snapshot and either restores data from existing
     * snapshot or otherwise invokes $callback and creates a new snapshot.
     *
     * Using this can speed up tests when expensive setUp() operations are
     * needed in all tests of a test case: The first test performs the
     * expensive operations in $callback, sub sequent tests of this test
     * case then just import the resulting database rows.
     *
     * An example to this are the "SiteHandling" core tests, which create
     * a starter scenario using DataHandler based on Yaml files.
     */
    protected function withDatabaseSnapshot(callable $callback): void
    {
        $connection = $this->getConnectionPool()->getConnectionByName(
            ConnectionPool::DEFAULT_CONNECTION_NAME
        );
        $accessor = new DatabaseAccessor($connection);
        $snapshot = DatabaseSnapshot::instance();
        if ($this->isFirstTest) {
            $callback();
            $snapshot->create($accessor, $connection);
        } else {
            $snapshot->restore($accessor, $connection);
        }
    }

    /**
     * Uses a 7 char long hash of class name as identifier.
     */
    protected static function getInstanceIdentifier(): string
    {
        return substr(sha1(static::class), 0, 7);
    }

    protected static function getInstancePath(): string
    {
        $identifier = self::getInstanceIdentifier();
        return ORIGINAL_ROOT . 'typo3temp/var/tests/functional-' . $identifier;
    }
}
