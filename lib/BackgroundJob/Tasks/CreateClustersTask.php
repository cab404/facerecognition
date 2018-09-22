<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\FaceRecognition\BackgroundJob\Tasks;

use OCP\IConfig;
use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;

use OCA\FaceRecognition\Db\FaceNewMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Helper\Euclidean;

use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

/**
 * Taks that, for each user, creates person clusters for each.
 */
class CreateClustersTask extends FaceRecognitionBackgroundTask {
	/** @var IConfig Config */
	private $config;

	/** @var PersonMapper Person mapper*/
	private $personMapper;

	/** @var ImageMapper Image mapper*/
	private $imageMapper;

	/** @var FaceNewMapper Face mapper*/
	private $faceMapper;

	/**
	 * @param IConfig $config Config
	 */
	public function __construct(IConfig $config, PersonMapper $personMapper, ImageMapper $imageMapper, FaceNewMapper $faceMapper) {
		parent::__construct();
		$this->config = $config;
		$this->personMapper = $personMapper;
		$this->imageMapper = $imageMapper;
		$this->faceMapper = $faceMapper;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Create new persons or update existing persons";
	}

	/**
	 * @inheritdoc
	 */
	public function do(FaceRecognitionContext $context) {
		$this->setContext($context);

		$fullImageScanDone = $this->config->getAppValue('facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
		if ($fullImageScanDone != 'true') {
			// If not all images are not interested in the database, we cannot determine when we should start clustering.
			// Since this is step in beggining, just bail out.
			$this->logInfo('Skipping cluster creation, as not even existing images are found and inserted in database');
			return;
		}

		// We cannot yield inside of Closure, so we need to extract all users and iterate outside of closure.
		// However, since we don't want to do deep copy of IUser, we keep only UID in this array.
		//
		$eligable_users = array();
		if (is_null($this->context->user)) {
			$this->context->userManager->callForSeenUsers(function (IUser $user) use (&$eligable_users) {
				$eligable_users[] = $user->getUID();
			});
		} else {
			$eligable_users[] = $this->context->user->getUID();
		}

		foreach($eligable_users as $user) {
			$this->createClusterIfNeeded($user);
		}
	}

	private function createClusterIfNeeded(string $userId) {
		// Check that we processed enough images to start creating clusters
		//
		$modelId = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		$hasPersons = $this->personMapper->countPersons($userId) > 0;

		// Depending on whether we already have clusters, decide if we should create/recreate them.
		//
		if ($hasPersons) {
			// todo: find all faces that are in DB, but are not in user’s clusters.
			// If we detect more than 10 faces like this,
			// or if more than 2h since any of these is passed,
			// or if “is_valid” (UserCluster table) is false,
			// start new round of clustering for that user.
		} else {
			// These are basic criteria without which we should not even consider creating clusters.
			// These clusters will be small and not "stable" enough and we should better wait for more images to come.
			// todo: 2 queries to get these 2 counts, can we do this smarter?
			$imageCount = $this->imageMapper->countUserImages($userId, $modelId);
			$imageProcessed = $this->imageMapper->countUserProcessedImages($userId, $modelId);
			$percentImagesProcessed = $imageProcessed / floatval($imageCount);
			$facesCount = $this->faceMapper->countFaces($userId, $modelId);
			// todo: get rid of magic numbers (move to config)
			if (($facesCount < 1000) && ($imageCount < 100) && ($percentImagesProcessed < 0.95)) {
				$this->logInfo(
					'Skipping cluster creation, not enough data (yet) collected. ' .
					'For cluster creation, you need either one of the following:');
				$this->logInfo(sprintf('* have 1000 faces already processed (you have %d),', $facesCount));
				$this->logInfo(sprintf('* have 100 images (you have %d),', $imageCount));
				$this->logInfo(sprintf('* or you need to have 95%% of you images processed (you have %f)', $percentImagesProcessed));
				return;
			}
		}

		$faces = $this->faceMapper->getFaces($userId, $modelId);
		$this->logInfo(count($faces) . ' faces found for clustering');

		// Cluster is associative array where key is person ID.
		// Value is array of face IDs. For old clusters, person IDs are some existing person IDs,
		// and for new clusters is whatever chinese whispers decides to identify them.
		//
		$currentClusters = $this->getCurrentClusters($faces);
		$newClusters = $this->getNewClusters($faces);
		$this->logInfo(count($newClusters) . ' clusters found for clustering');
		// New merge
		$mergedClusters = $this->mergeClusters($currentClusters, $newClusters);
		$this->personMapper->mergeClusterToDatabase($userId, $currentClusters, $newClusters);
	}

	private function getCurrentClusters(array $faces): array {
		$chineseClusters = array();
		foreach($faces as $face) {
			if ($face->person != null) {
				if (!isset($chineseClusters[$face->person])) {
					$chineseClusters[$face->person] = array();
				}
				$chineseClusters[$face->person][] = $face->id;
			}
		}
		return $chineseClusters;
	}

	private function getNewClusters(array $faces): array {
		// Create edges for chinese whispers
		$euclidean = new Euclidean();
		$edges = array();
		for ($i = 0; $i < count($faces); $i++) {
			$face1 = $faces[$i];
			for ($j = $i; $j < count($faces); $j++) {
				$face2 = $faces[$j];
				// todo: can't this distance be a method in $face1->distance($face2)?
				$distance = $euclidean->distance($face1->descriptor, $face2->descriptor);
				// todo: extract this magic number to app param
				if ($distance < 0.5) {
					$edges[] = array($i, $j);
				}
			}
		}

		$newChineseClustersByIndex = dlib_chinese_whispers($edges);
		$newClusters = array();
		for ($i = 0; $i < count($newChineseClustersByIndex); $i++) {
			if (!isset($newClusters[$newChineseClustersByIndex[$i]])) {
				$newClusters[$newChineseClustersByIndex[$i]] = array();
			}
			$newClusters[$newChineseClustersByIndex[$i]][] = $faces[$i]->id;
		}

		return $newClusters;
	}

	/**
	 * todo: only reason this is public is because of tests. Go figure it out better.
	 */
	public function mergeClusters(array $oldCluster, array $newCluster): array {
		// Create map of face transitions
		$transitions = array();
		foreach ($newCluster as $newPerson=>$newFaces) {
			foreach ($newFaces as $newFace) {
				$oldPersonFound = null;
				foreach ($oldCluster as $oldPerson => $oldFaces) {
					if (in_array($newFace, $oldFaces)) {
						$oldPersonFound = $oldPerson;
						break;
					}
				}
				$transitions[$newFace] = array($oldPersonFound, $newPerson);
			}
		}
		// Count transitions
		$transitionCount = array();
		foreach ($transitions as $transition) {
			$key = $transition[0] . ':' . $transition[1];
			if (array_key_exists($key, $transitionCount)) {
				$transitionCount[$key]++;
			} else {
				$transitionCount[$key] = 1;
			}
		}
		// Create map of new person -> old persion transitions
		$newOldPersonMapping = array();
		$oldPersonProcessed = array(); // store this, so we don't waste cycles for in_array()
		arsort($transitionCount);
		foreach ($transitionCount as $transitionKey => $count) {
			$transition = explode(":", $transitionKey);
			$oldPerson = intval($transition[0]);
			$newPerson = intval($transition[1]);
			if (!array_key_exists($newPerson, $newOldPersonMapping)) {
				if (($oldPerson == 0) || (!array_key_exists($oldPerson, $oldPersonProcessed))) {
					$newOldPersonMapping[$newPerson] = $oldPerson;
					$oldPersonProcessed[$oldPerson] = 0;
				} else {
					$newOldPersonMapping[$newPerson] = 0;
				}
			}
		}
		// Starting with new cluster, convert all new person IDs with old person IDs
		$maxOldPersonId = 1;
		if (count($oldCluster) > 0) {
			$maxOldPersonId = max(array_keys($oldCluster)) + 1;
		}

		$result = array();
		foreach ($newCluster as $newPerson => $newFaces) {
			$oldPerson = $newOldPersonMapping[$newPerson];
			if ($oldPerson == 0) {
				$result[$maxOldPersonId] = $newFaces;
				$maxOldPersonId++;
			} else {
				$result[$oldPerson] = $newFaces;
			}
		}
		return $result;
	}
}