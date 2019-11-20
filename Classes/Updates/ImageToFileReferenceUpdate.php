<?php
namespace Evoweb\SfBooks\Updates;

/**
 * This file is developed by evoWeb.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Upgrade wizard which goes through all files referenced in the tt_content.image filed
 * and creates sys_file records as well as sys_file_reference records for the individual usages.
 */
class ImageToFileReferenceUpdate implements \TYPO3\CMS\Install\Updates\UpgradeWizardInterface
{
    /**
     * Number of records fetched per database query
     * Used to prevent memory overflows for huge databases
     */
    const RECORDS_PER_QUERY = 1000;

    /**
     * @var string
     */
    protected $description = '';

    /**
     * @var \TYPO3\CMS\Core\Resource\ResourceStorage
     */
    protected $storage;

    /**
     * @var \TYPO3\CMS\Core\Log\Logger
     */
    protected $logger;

    /**
     * Table fields to migrate
     * target paths are relative to storage 0 folder
     *
     * @var array
     */
    protected $tables = [
        'tx_sfbooks_domain_model_book' => [
            'cover' => [
                'sourcePath' => 'uploads/tx_sfbooks/',
                'targetPath' => '_migrated/sf_books/book_cover/',
            ],
            'cover_large' => [
                'sourcePath' => 'uploads/tx_sfbooks/',
                'targetPath' => '_migrated/sf_books/book_cover_large/',
            ],
            'sample_pdf' => [
                'sourcePath' => 'uploads/tx_sfbooks/',
                'targetPath' => '_migrated/sf_books/book_sample_pdf/',
            ],
        ],
    ];

    /**
     * Table to migrate records from
     *
     * @var string
     */
    protected $table = '';

    /**
     * Table field containing files to migration
     *
     * @var string
     */
    protected $field = '';

    /**
     * Source folder the file resides in
     *
     * @var string
     */
    protected $sourcePath = '';

    /**
     * Target folder after migration relative to default storage
     *
     * @var string
     */
    protected $targetPath = '';

    /**
     * @var \TYPO3\CMS\Core\Registry
     */
    protected $registry;

    /**
     * @var string
     */
    protected $registryNamespace = 'sfBooksFileUpdateWizard';

    /**
     * @var array
     */
    protected $recordOffset = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        foreach ($this->tables as $table => $fields) {
            foreach ($fields as $field => $paths) {
                if (!$this->isWizardDone($table, $field)) {
                    $this->table = $table;
                    $this->field = $field;
                    $this->sourcePath = $paths['sourcePath'];
                    $this->targetPath = $paths['targetPath'];

                    $this->description .= 'Migrate all file relations from ' . $table
                        . '.' . $field . ' to sys_file and add sys_file_references';

                    break;
                }
            }

            if ($this->table !== '') {
                break;
            }
        }
    }

    /**
     * @return string Unique identifier of this updater
     */
    public function getIdentifier(): string
    {
        return 'sfBooksImageToFileReferenceUpdate';
    }

    /**
     * @return string Title of this updater
     */
    public function getTitle(): string
    {
        return 'Migrate uploaded files of sf_books';
    }

    /**
     * @return string Longer description of this updater
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return bool True if there are records to update
     */
    public function updateNecessary(): bool
    {
        return $this->table !== '';
    }

    /**
     * @return string[] All new fields and tables must exist
     */
    public function getPrerequisites(): array
    {
        return [
            \TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite::class
        ];
    }

    /**
     * Performs the update
     *
     * @return bool
     */
    public function executeUpdate(): bool
    {
        $this->logger = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);

        $customMessage = '';
        try {
            $this->prepareOffset();
            $this->getStorage();

            do {
                $records = $this->getRecords();
                foreach ($records as $record) {
                    $this->migrateField($record, $customMessage, $dbQueries);
                }
                $this->getRegistry()->set($this->registryNamespace, 'recordOffset', $this->recordOffset);
            } while (count($records) === self::RECORDS_PER_QUERY);

            $this->markWizardAsDone($this->table, $this->field);
            $this->getRegistry()->remove($this->registryNamespace, 'recordOffset');
        } catch (\Exception $e) {
            $customMessage .= PHP_EOL . $e->getMessage();
        }

        return empty($customMessage);
    }

    protected function getRegistry(): \TYPO3\CMS\Core\Registry
    {
        if ($this->registry === null) {
            $this->registry = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Registry::class);
        }
        return $this->registry;
    }

    /**
     * Get if wizard is done for table field combination
     *
     * @param string $table
     * @param string $field
     *
     * @return bool
     */
    protected function isWizardDone(string $table, string $field)
    {
        $wizard = self::class . '/' . $table . '/' . $field;
        return $this->getRegistry()->get($this->registryNamespace, $wizard, false);
    }

    /**
     * Marks some wizard as being "seen" so that it not shown again.
     *
     * Writes the info to registry
     *
     * @param string $table
     * @param string $field
     */
    protected function markWizardAsDone(string $table, string $field)
    {
        $wizard = self::class . '/' . $table . '/' . $field;
        $this->getRegistry()->set($this->registryNamespace, $wizard, true);
    }

    protected function prepareOffset()
    {
        $this->recordOffset = $this->getRegistry()->get($this->registryNamespace, 'recordOffset', []);
        if (!isset($this->recordOffset[$this->table])) {
            $this->recordOffset[$this->table] = 0;
        }
    }

    protected function getStorage()
    {
        /** @var $storageRepository \TYPO3\CMS\Core\Resource\StorageRepository */
        $storageRepository = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\StorageRepository::class);
        $allStorages = $storageRepository->findAll();
        foreach ($allStorages as $storage) {
            if ($storage->isDefault()) {
                $this->storage = $storage;
            }
        }
    }

    /**
     * Get records from table where the field to migrate is not empty (NOT NULL and != '')
     * and also not numeric (which means that it is migrated)
     *
     * @return array
     * @throws \RuntimeException
     */
    protected function getRecords()
    {
        $queryBuilder = $this->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(
                \TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class
            ));

        try {
            $queryBuilder
                ->select('uid', 'pid', $this->field)
                ->from($this->table)
                ->where(
                    $queryBuilder->expr()->isNotNull($this->field),
                    $queryBuilder->expr()->neq(
                        $this->field,
                        $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                    ),
                    // make sure that the field does not contain only numbers
                    $queryBuilder->expr()->comparison(
                        'CAST(CAST(' . $queryBuilder->quoteIdentifier($this->field) . ' AS DECIMAL) AS CHAR)',
                        \TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder::NEQ,
                        'CAST(' . $queryBuilder->quoteIdentifier($this->field) . ' AS CHAR)'
                    )
                )
                ->orderBy('uid')
                ->setFirstResult($this->recordOffset[$this->table] ?? 0)
                ->setMaxResults(self::RECORDS_PER_QUERY);

            $result = $queryBuilder->execute();

            $dbQueries[] = $queryBuilder->getSQL();

            return $result->fetchAll();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Database query failed. Error was: ' . $e->getPrevious()->getMessage(),
                1476050084
            );
        }
    }

    /**
     * Migrates a single field.
     *
     * @param array $row
     * @param string $customMessage
     * @param array $dbQueries
     *
     * @throws \Exception
     */
    protected function migrateField(array $row, string &$customMessage, array &$dbQueries)
    {
        $fieldItems = GeneralUtility::trimExplode(',', $row[$this->field], true);
        if (empty($fieldItems) || is_numeric($row[$this->field])) {
            return;
        }
        $fileadminDirectory = rtrim($GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'], '/') . '/';
        $i = 0;

        if (!Environment::getPublicPath()) {
            throw new \Exception('Public path was undefined.', 1476107387);
        }

        $storageUid = (int)$this->storage->getUid();

        foreach ($fieldItems as $item) {
            $fileUid = null;
            $sourcePath = Environment::getPublicPath() . '/' . $this->sourcePath . $item;
            $targetDirectory = Environment::getPublicPath() . '/' . $fileadminDirectory . $this->targetPath;
            $targetPath = $targetDirectory . basename($item);

            // maybe the file was already moved, so check if the original file still exists
            if (file_exists($sourcePath)) {
                if (!is_dir($targetDirectory)) {
                    GeneralUtility::mkdir_deep($targetDirectory);
                }

                // see if the file already exists in the storage
                $fileSha1 = sha1_file($sourcePath);

                $queryBuilder = $this->getQueryBuilderForTable('sys_file');
                $queryBuilder->getRestrictions()->removeAll();
                $existingFileRecord = $queryBuilder->select('uid')->from('sys_file')->where(
                    $queryBuilder->expr()->eq(
                        'sha1',
                        $queryBuilder->createNamedParameter($fileSha1, \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->eq(
                        'storage',
                        $queryBuilder->createNamedParameter($storageUid, \PDO::PARAM_INT)
                    )
                )->execute()->fetch();

                // the file exists, the file does not have to be moved again
                if (is_array($existingFileRecord)) {
                    $fileUid = $existingFileRecord['uid'];
                } else {
                    // just move the file (no duplicate)
                    rename($sourcePath, $targetPath);
                }
            }

            if ($fileUid === null) {
                // get the File object if it hasn't been fetched before
                try {
                    // if the source file does not exist, we should just continue, but leave a message in the docs;
                    // ideally, the user would be informed after the update as well.
                    /** @var \TYPO3\CMS\Core\Resource\File $file */
                    $file = $this->storage->getFile($this->targetPath . $item);
                    $fileUid = $file->getUid();
                } catch (\InvalidArgumentException $e) {
                    // no file found, no reference can be set
                    $this->logger->notice(
                        'File ' . $this->sourcePath . $item . ' does not exist. Reference was not migrated.',
                        [
                            'table' => $this->table,
                            'record' => $row,
                            'field' => $this->field,
                        ]
                    );

                    $format =
                        'File \'%s\' does not exist. Referencing field: %s.%d.%s. The reference was not migrated.';
                    $message = sprintf(
                        $format,
                        $this->sourcePath . $item,
                        $this->table,
                        $row['uid'],
                        $this->field
                    );
                    $customMessage .= PHP_EOL . $message;
                    continue;
                }
            }

            if ($fileUid > 0) {
                $fields = [
                    'fieldname' => $this->field,
                    'table_local' => 'sys_file',
                    'pid' => ($this->table === 'pages' ? $row['uid'] : $row['pid']),
                    'uid_foreign' => $row['uid'],
                    'uid_local' => $fileUid,
                    'tablenames' => $this->table,
                    'crdate' => time(),
                    'tstamp' => time(),
                    'sorting' => ($i + 256),
                    'sorting_foreign' => $i,
                ];

                $queryBuilder = $this->getQueryBuilderForTable('sys_file_reference');
                $queryBuilder->insert('sys_file_reference')->values($fields)->execute();
                $dbQueries[] = str_replace(LF, ' ', $queryBuilder->getSQL());
                ++$i;
            }
        }

        // Update referencing table's original field to now contain the count of references,
        // but only if all new references could be set
        if ($i === count($fieldItems)) {
            $queryBuilder = $this->getQueryBuilderForTable($this->table);
            $queryBuilder->update($this->table)->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($row['uid'], \PDO::PARAM_INT)
                )
            )->set($this->field, $i)->execute();
            $dbQueries[] = str_replace(LF, ' ', $queryBuilder->getSQL());
        } else {
            $this->recordOffset[$this->table]++;
        }
    }


    protected function getQueryBuilderForTable($table): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getQueryBuilderForTable($table);
    }
}
