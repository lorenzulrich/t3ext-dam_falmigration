<?php

namespace B13\DamFalmigration\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Benjamin Mack <typo3@b13.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\DamFalmigration\Service\MigrateRelations;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Command Controller to execute DAM to FAL Migration scripts
 *
 * @package B13\DamFalmigration
 * @subpackage Controller
 */
class DamMigrationCommandController extends \TYPO3\CMS\Extbase\Mvc\Controller\CommandController {

	/**
	 * goes through all DAM files and checks if they have a counterpart in the sys_file
	 * table. If not, fetch the file (via the storage, which indexes the file directly)
	 * and update the DAM DB table
	 * Please note that this does not migrate the metadata
	 * this command can be run multiple times
	 *
	 * @param \string $storageUid the UID of the storage (usually 1, don't modify if you are unsure)
	 */
	public function connectDamRecordsWithSysFileCommand($storageUid = 1) {

		$fileFactory = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance();

		// create the storage object
		$storageObject = $fileFactory->getStorageObject($storageUid);
		$migratedFiles = 0;

		// get all DAM records that have not been migrated yet
		$damRecords = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'*',
			'tx_dam',
			// check for all FAL records that are there, that have been migrated already
			// seen by the "_migrateddamuid" flag
			'deleted=0 AND uid NOT IN (SELECT _migrateddamuid FROM sys_file WHERE _migrateddamuid > 0)'
		);

		$this->outputLine('Found ' . count($damRecords) . ' DAM records with no connected sys_file entry');

		foreach ($damRecords as $damRecord) {

			$damUid = $damRecord['uid'];
			$fileIdentifier = $damRecord['file_path'] . $damRecord['file_name'];

			// right now, only files in fileadmin/ are supported
			if (GeneralUtility::isFirstPartOfStr($fileIdentifier, 'fileadmin/') === FALSE) {
				continue;
			}

			// strip away the "fileadmin/" prefix
			$fullFileName = substr($fileIdentifier, 10);

			// check if the DAM record is already indexed for FAL (based on the filename)
			$fileObject = NULL;
			try {
				$fileObject = $storageObject->getFile($fullFileName);
			} catch(\TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException $e) {
				// file not found jump to next file
				continue;
			} catch(\Exception $e) {
				$this->outputLine('File not found: "' . $fullFileName . '"');
				continue;
			}

			// add the migrated uid of the DAM record to the FAL record
			if ($fileObject instanceof \TYPO3\CMS\Core\Resource\File) {
				if ($fileObject->isMissing()) {
					$this->outputLine('FAL did not find any file resource for DAM record. DAM uid: ' . $damUid . ': "' . $fullFileName . '"');
					continue;
				}
				$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
					'sys_file',
					'uid=' . $fileObject->getUid(),
					array('_migrateddamuid' => $damUid)
				);
				$migratedFiles++;
				$this->outputLine('DAM File is now indexed for FAL. FAL uid: ' . $fileObject->getUid() . ', DAM uid: ' . $damUid . ': "' . $fullFileName . '"');
			} else {
				$this->outputLine('FAL did not find any file resource for DAM record. DAM uid: ' . $damUid . ': "' . $fullFileName . '"');
			}
		}

		// print a message
		if ($migratedFiles > 0) {
			$this->outputLine('Migration successful: Migrated ' . $migratedFiles . ' files.');
		} else {
			$this->outputLine('Migration not needed: All files have been migrated already.');
		}
	}


	/**
	 * migrates DAM metadata to FAL metadata
	 * searches for all sys_file records that don't have any titles yet
	 * with a connection to a _dammigration record
	 */
	public function migrateDamMetadataCommand() {
		$recordsToMigrate = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'DISTINCT m.uid AS metadata_uid, f._migrateddamuid AS damuid, d.*',
			'sys_file f, sys_file_metadata m, tx_dam d',
			'm.file=f.uid AND f._migrateddamuid=d.uid AND f._migrateddamuid > 0 AND m.title IS NULL'
		);

		$this->outputLine('Found ' . count($recordsToMigrate) . ' sys_file_metadata records that have no title but associated with a DAM record that has a title');

		$migratedRecords = 0;
		$hasAdvancedMetadata = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('filemetadata');
		foreach ($recordsToMigrate as $rec) {
			$metaData = array(
				'title' => $rec['title'],
				'description' => $rec['description'],
				'alternative' => $rec['alt_text']
			);
			if ($hasAdvancedMetadata) {
				$metaData['visible'] = $rec['hidden'];
				$metaData['keywords'] = $rec['keywords'];
				$metaData['caption'] = $rec['caption'];
				$metaData['publisher'] = $rec['publisher'];
				$metaData['location_country'] = $rec['loc_country'];
				$metaData['location_city'] = $rec['loc_city'];
				$metaData['download_name'] = $rec['file_dl_name'];
				$metaData['creator'] = $rec['creator'];
				$metaData['fe_groups'] = $rec['fe_group'];
				$metaData['content_creation_date'] = $rec['date_cr'];
				$metaData['content_modification_date'] = $rec['date_mod'];
				$metaData['note'] = $rec['instructions'];
				$metaData['unit'] = $rec['height_unit'];
				$metaData['color_space'] = $rec['color_space'];
				$metaData['language'] = $rec['language'];
			}
			$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
				'sys_file_metadata',
				'uid = ' . intval($rec['metadata_uid']),
				$metaData
			);
			$migratedRecords++;
		}

		$this->outputLine('Migrated title, description and alt_text for ' . $migratedRecords . ' records');
	}


	/**
	 * migrates the <media DAM_UID target title>Linktext</media>
	 * to <link file:29643 - download>My link to a file</link>
	 *
	 * @param \string $table the table to look for
	 * @param \string $field the DB field to look for
	 */
	public function migrateMediaTagsInRteCommand($table = 'tt_content', $field = 'bodytext') {
		$recordsToMigrate = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'uid, ' . $field,
			$table,
			'deleted=0 AND ' . $field . ' LIKE "%<media%"'
		);

		$this->outputLine('Found ' . count($recordsToMigrate) . ' ' . $table . ' records that have a "<media>" tag in the field ' . $field);
		foreach ($recordsToMigrate as $rec) {
			$originalContent = $rec[$field];
			$finalContent = $originalContent;
			$results = array();
			preg_match_all('/<media ([0-9]+)([^>]*)>(.*?)<\/media>/', $originalContent, $results, PREG_SET_ORDER);
			if (count($results)) {
				foreach ($results as $result) {
					$searchString = $result[0];
					$damUid = $result[1];
					// see EXT:dam/mediatag/class.tx_dam_rtetransform_mediatag.php
					list($linkTarget, $linkClass, $linkTitle) = explode(' ', trim($result[2]), 3);
					$linkText = $result[3];
					$this->outputLine('Replacing "' . $result[0] . '" with DAM UID ' . $damUid . ' (target ' . $linkTarget . '; class ' . $linkClass . '; title "' . $linkTitle . '") and linktext "' . $linkText . '"');
					// fetch the DAM uid from sys_file
					// and replace the full tag with a valid href="file:FALUID"
					// <link file:29643 - download>My link to a file</link>
					$falRecord = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('uid', 'sys_file', '_migrateddamuid=' . intval($damUid));
					if (is_array($falRecord)) {
						$replaceString = '<link file:' . $falRecord['uid'] . ' ' . $result[2] . '>' . $linkText . '</link>';
						$finalContent = str_replace($searchString, $replaceString, $finalContent);
					}
				}
				// update the record
				if ($finalContent !== $originalContent) {
					$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
						$table,
						'uid=' . $rec['uid'],
						array($field => $finalContent)
					);
					$this->outputLine('Updated ' . $table . ':' . $rec['uid'] . ' with: ' . $finalContent);
				}
			} else {
				$this->outputLine('Nothing found: ' . $originalContent);
			}
		}
		$this->outputLine('DONE');
	}


	/**
	 * migrate all DAM categories to sys_file_collection records,
	 * while also migrating the references if they don't exist yet
	 * as a pre-requisite, there needs to be sys_file records that
	 * have been migrated from DAM
	 *
	 * @param \string $migrateReferences whether just the categories should be migrated or the references as well
	 */
	public function migrateDamCategoriesToFalCollectionsCommand($migrateReferences = TRUE) {

		$fileCollectionStoragePid = 44;

		$databaseHelper = \B13\DamFalmigration\Helper\DatabaseHelper::getInstance();

		// get all categories that are in use
		$damCategories = $databaseHelper->getAllDamCategories();

		if (count($damCategories) > 0) {

			// fetch all FAL records that are there, that have been migrated already
			$falRecords = $databaseHelper->getAllMigratedFalRecords();

			// first, find all DAM records that are attached to the DAM categories
			$mmRelations = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
				'uid_local AS damuid, uid_foreign AS categoryuid',
				'tx_dam_mm_cat',
				'uid_foreign IN (' . implode(',', array_keys($damCategories)) . ')'
			);

			foreach ($mmRelations as $relation) {
				$damCategories[$relation['categoryuid']]['files'][] = $falRecords[$relation['damuid']]['uid'];
			}

			// create FAL collections out of the categories
			// get all DAM relations

			// add the categories as "sys_file_collection"
			foreach ($damCategories as $damCategoryUid => $categoryInfo) {

				if (!is_numeric($damCategoryUid) || empty($damCategoryUid)) {
					continue;
				}

				// don't migrate categories with no files
				if (count($categoryInfo['files']) == 0) {
					$this->outputLine('Category ' . $categoryInfo['title'] . ' was not added since it has no valid FAL record attached to it');
					continue;
				}

				// check if there is a file collection with that category information
				$existingFileCollection = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow(
					'uid, _migrateddamcatuid',
					'sys_file_collection',
					'_migrateddamcatuid=' . intval($damCategoryUid)
				);

				if (is_array($existingFileCollection)) {
					$damCategories[$damCategoryUid]['falcollectionuid'] = $existingFileCollection['uid'];
					$this->outputLine('DAM category ' . $damCategoryUid . ' has the existing FAL collection ' . $existingFileCollection['uid']);

				} else {

					$GLOBALS['TYPO3_DB']->exec_INSERTquery(
						'sys_file_collection',
						array(
							'pid'   => $fileCollectionStoragePid,
							'title' => $categoryInfo['title'],
							'_migrateddamcatuid' => $damCategoryUid
						)
					);
					$damCategories[$damCategoryUid]['falcollectionuid'] = $GLOBALS['TYPO3_DB']->sql_insert_id();
					$this->outputLine('New FAL collection added (uid ' . $damCategories[$damCategoryUid]['falcollectionuid'] . ') from DAM category ' . $damCategoryUid);
				}
			}


			// add the FAL records as IRRE relations (sys_file_reference), if the reference does not exist yet
			if ($migrateReferences) {
				foreach ($damCategories as $damCategoryUid => $categoryInfo) {

					if (!is_numeric($damCategoryUid) || empty($damCategoryUid)) {
						continue;
					}

					$falCollectionUid = intval($categoryInfo['falcollectionuid']);

					if (count($categoryInfo['files']) > 0) {
						foreach ($categoryInfo['files'] as $falUid) {
							$falUid = intval($falUid);
							if ($falUid > 0) {
								$r = $databaseHelper->addToFileReferenceIfNotExists($falUid, $falCollectionUid, 'sys_file_collection', 'files');
								if ($r) {
									$this->outputLine('Added FAL file ' . $falUid . ' to FAL collection ' . $falCollectionUid);
								} else {
									$this->outputLine('FAL file relation of file ' . $falUid . ' to FAL collection ' . $falCollectionUid . ' already exists. Nothing modified.');
								}
							}
						}
					} else {
						$this->outputLine('Notice: Collection / DAM Category "' . $categoryInfo['title'] . '" (DAM Category ID ' . $damCategoryUid . '/FAL Collection ID ' . $falCollectionUid . ') has no files attached to it');
					}
				}
			}

			$this->outputLine('Migration done.');
		} else {
			$this->outputLine('No categories found, nothing migrated.');
		}
	}


	/**
	 * migrate all damfrontend_pi1 plugins to tt_content.uploads with file_collection
	 * usually used in conjunction with / after migrateDamCategoriesToFalCollectionsCommand()
	 */
	public function migrateDamFrontendPluginsCommand() {

		// get all FAL collections that have been migrated so far
		$migratedFileCollections = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'uid, _migrateddamcatuid AS damcatuid',
			'sys_file_collection',
			'_migrateddamcatuid > 0',
			'',
			'',
			'',
			'damcatuid'
		);

		// find all dam_frontend plugins
		$damFrontendPlugins = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'pi_flexform, list_type, CType, uid, pid, deleted',
			'tt_content',
			'list_type="dam_frontend_pi1" AND CType="list" AND deleted=0 AND pi_flexform!=""'
			//'((list_type="dam_frontend_pi1" AND CType="list") OR CType="uploads") AND deleted=0 AND pi_flexform!=""'
		);

		foreach ($damFrontendPlugins as &$plugin) {
			$plugin['pi_flexform'] = GeneralUtility::xml2array($plugin['pi_flexform']);
			$plugin['pi_flexform'] = $plugin['pi_flexform']['data'];
			$plugin['damfrontend_staticCatSelection'] = $plugin['pi_flexform']['sSelection']['lDEF']['useStaticCatSelection']['vDEF'];
			$plugin['damfrontend_usedCategories'] = $plugin['pi_flexform']['sSelection']['lDEF']['catMounts']['vDEF'];
		}

		$this->outputLine('Found ' . count($damFrontendPlugins) . ' plugins of dam_frontend_pi1');


		// replace the plugins with the new ones
		foreach ($damFrontendPlugins as $plugin) {

			$usedDamCategories = GeneralUtility::trimExplode(',', $plugin['damfrontend_usedCategories'], TRUE);
			$fileCollections = array();

			foreach ($usedDamCategories as $damCategoryUid) {
				if (isset($migratedFileCollections[$damCategoryUid])) {
					$fileCollections[] = $migratedFileCollections[$damCategoryUid]['uid'];
				}
			}

			$this->outputLine('Categories for plugin ' . $plugin['uid'] . ': ' . implode(',', $fileCollections) . ' (originally: ' . $plugin['damfrontend_usedCategories'] . ')');

			if (count($fileCollections) > 0) {
				$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
					'tt_content',
					'uid=' . intval($plugin['uid']),
					array(
						'CType' => 'uploads',
						'file_collections' => implode(',', $fileCollections),
					)
				);
			} else {
				$this->outputLine('Plugin ' . $plugin['uid'] . ' not migrated because there are no file collections');
			}
		}
	}


	/**
	 * checks if there are multiple entries in sys_file_reference that contain
	 * the same uid_local and uid_foreign with sys_file_collection references
	 * and removes the duplicates
	 * NOTE: this command is usually *NOT* necessary, but only if something went wrong
	 */
	public function cleanupDuplicateFalCollectionReferencesCommand() {
		$references = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'uid, uid_local, uid_foreign, COUNT(uid) AS amountOfRows',
			'sys_file_reference',
			'tablenames="sys_file_collection" AND fieldname="files" AND deleted=0',
			'uid_foreign, uid_local',	// ROLLUP
			'uid_foreign, uid_local'
		);
		$this->outputLine('Found ' . count($references) . ' references to sys_file_collection');
		$affectedRecords=0;
		foreach ($references as $ref) {
			// this reference has duplicates
			if ($ref['amountOfRows'] > 1) {
				$GLOBALS['TYPO3_DB']->exec_DELETEquery(
					'sys_file_reference',
					'uid != ' . $ref['uid'] . ' AND tablenames="sys_file_collection" AND fieldname="files" AND deleted=0 AND uid_local=' . $ref['uid_local'] . ' AND uid_foreign=' . $ref['uid_foreign']
				);
				$affectedRecords++;
			}
		}
		$this->outputLine('Cleaned up ' . $affectedRecords . ' duplicates of references');
	}

	/**
	 * updates the reference index
	 */
	public function updateReferenceIndexCommand() {
				// update the reference index
			$refIndexObj = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Database\\ReferenceIndex');
//			list($headerContent, $bodyContent, $errorCount) = $refIndexObj->updateIndex('check', FALSE);
			list($headerContent, $bodyContent, $errorCount) = $refIndexObj->updateIndex('update', FALSE);
	}

	/**
	 * migrate relations to dam records that dam_ttcontent
	 * and dam_uploads introduced
	 *
	 * it is highly recommended to update the ref index afterwards
	 */
	public function migrateRelationsCommand() {
		/** @var MigrateRelations $migrateRelationsService */
		$migrateRelationsService = $this->objectManager->get('TYPO3\\CMS\\DamFalmigration\\Service\\MigrateRelations');
		$message = $migrateRelationsService->execute();

		if($message->getTitle()) {
			$this->outputLine($message->getTitle());
		}
		if($message->getMessage()) {
			$this->outputLine($message->getMessage());
		}
		if($message->getSeverity() !== FlashMessage::OK) {
			$this->sendAndExit(1);
		}
	}

	/**
	 * Migrate DAM categories to FAL categories
	 *
	 * @param integer $initialParentUid Initial parent UID
	 * @param integer $storagePid Store on PID
	 * @return bool
	 */
	public function migrateDamCategoriesCommand($initialParentUid = 0, $storagePid) {
		$databaseHelper = \B13\DamFalmigration\Helper\DatabaseHelper::getInstance();
		if ($databaseHelper->isTableAvailable('tx_dam_cat')) {
			// if a parent uid is given but not available, set initial uid to 0
			if ($initialParentUid > 0 && !$databaseHelper->checkInitialParentAvailable()) {
				$initialParentUid = 0;
			}

			// $parrentUidMap[oldUid] = 'newUid';
			$parentUidMap = array();
			$parentUidMap[0] = $initialParentUid;

			//******** STEP 1 - Get all categories *********//
			$damCategories = $databaseHelper->getAllNotYetMigratedDamCategoriesWithItemCount();

			//******** STEP 2 - re-sort category array *********//
			$damCategories = \TYPO3\CMS\DamFalmigration\Utility\GeneralUtility::sortCategories($damCategories, 0);

			//******** STEP 3 - Build category tree *********//
			$amountOfMigratedRecords = 0;
			foreach($damCategories as $category) {

				$newParentUid = $parentUidMap[$category['parent_id']];

				// create the new category in table sys_category
				$newUid = $databaseHelper->createNewCategory($category, $newParentUid, $storagePid);

				$parentUidMap[$category['uid']] = $newUid;
				$amountOfMigratedRecords++;
			}

			//******** STEP 3 - Migrate DAM category mountpoints to sys_category permissions *********//
			$this->migrateDamCategoryMountsToSysCategoryPerms('be_users');
			$this->migrateDamCategoryMountsToSysCategoryPerms('be_groups');

			if ($amountOfMigratedRecords > 0) {
				$this->outputLine(LocalizationUtility::translate('migrationSuccessful', 'dam_falmigration'));
				$this->outputLine(LocalizationUtility::translate('migratedFiles', 'dam_falmigration', array(0 => $amountOfMigratedRecords)));
			} else {
				$this->outputLine(LocalizationUtility::translate('migrationNotNecessary', 'dam_falmigration'));
				$this->outputLine(LocalizationUtility::translate('allFilesMigrated', 'dam_falmigration', array(0 => $amountOfMigratedRecords)));
			}
		} else {
			$this->outputLine('Table tx_dam_cat is not available. So there is nothing to migrate.');
		}
	}

	/**
	 * Migrate tt_news_categorymounts to category_pems in either be_groups or be_users
	 *
	 * @param string $table either be_groups or be_users
	 */
	public function migrateDamCategoryMountsToSysCategoryPerms($table) {
		/** @var \TYPO3\CMS\Core\DataHandling\DataHandler $dataHandler */
		$dataHandler = GeneralUtility::makeInstance('TYPO3\CMS\Core\DataHandling\DataHandler');

		/* assign imported categories to be_groups or be_users */
		$whereClause = 'tx_dam_mountpoints != \'\'' . BackendUtility::deleteClause($table);
		$beGroupsOrUsersWithTxDamMountpoints = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('*', $table, $whereClause);
		$data = array();

		foreach ($beGroupsOrUsersWithTxDamMountpoints as $beGroupOrUser) {
			$txDamMountpoints = GeneralUtility::trimExplode(',', $beGroupOrUser['tx_dam_mountpoints']);
			$sysCategoryPermissions = array();
			foreach ($txDamMountpoints as $txDamMountpoint) {
				if (GeneralUtility::isFirstPartOfStr($txDamMountpoint, 'txdamCat:')) {
					// we only migrate DAM category mounts
					$damCategoryMountpoint = GeneralUtility::trimExplode(':', $txDamMountpoint);
					$whereClause = '_migrateddamcatuid = ' . $damCategoryMountpoint[1];
					$sysCategory = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('uid', 'sys_category', $whereClause);
					if (!empty($sysCategory)) {
						$sysCategoryPermissions[] = $sysCategory['uid'];
					}
				}


			}
			if (count($sysCategoryPermissions)) {
				$data[$table][$beGroupOrUser['uid']] = array(
					'category_perms' => implode(',', $sysCategoryPermissions) . ',' . $beGroupOrUser['category_perms']
				);
			}
		}
		$dataHandler->start($data, array());
		$dataHandler->admin = TRUE;
		$dataHandler->process_datamap();
	}

}
