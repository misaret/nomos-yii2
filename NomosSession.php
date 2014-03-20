<?php
/**
 * @link https://github.com/misaret/nomos-yii2/
 * @copyright Copyright (c) 2014 Vitalii Khranivskyi <misaret@gmail.com>
 * @license LICENSE file
 */

namespace misaret\yii;

use Yii;
use yii\web\Session;
use yii\base\InvalidConfigException;

/**
 * NomosSession extends [[Session]] by using Nomos Storage as session data storage.
 *
 * The following example shows how you can configure the application to use NomosSession:
 * Add the following to your application config under `components`:
 *
 * ~~~
 * 'session' => [
 *     'class' => '\misaret\yii2\web\NomosSession',
 *     // 'db' => 'mydb',
 *     // 'sessionTable' => 'my_session',
 * ]
 * ~~~
 *
 * @author Vitalii Khranivskyi <misaret@gmail.com>
 */
class NomosSession extends Session
{
	/**
	 * @var Connection|string the DB connection object or the application component ID of the DB connection.
	 * After the DbSession object is created, if you want to change this property, you should only assign it
	 * with a DB connection object.
	 */
	public $db = 'db';
	/**
	 * @var string the name of the DB table that stores the session data.
	 * The table should be pre-created as follows:
	 *
	 * ~~~
	 * CREATE TABLE tbl_session
	 * (
	 *     id CHAR(40) NOT NULL PRIMARY KEY,
	 *     expire INTEGER,
	 *     data BLOB
	 * )
	 * ~~~
	 *
	 * where 'BLOB' refers to the BLOB-type of your preferred DBMS. Below are the BLOB type
	 * that can be used for some popular DBMS:
	 *
	 * - MySQL: LONGBLOB
	 * - PostgreSQL: BYTEA
	 * - MSSQL: BLOB
	 *
	 * When using DbSession in a production server, we recommend you create a DB index for the 'expire'
	 * column in the session table to improve the performance.
	 */
	public $sessionTable = 'tbl_session';

	/**
	 * Initializes the DbSession component.
	 * This method will initialize the [[db]] property to make sure it refers to a valid DB connection.
	 * @throws InvalidConfigException if [[db]] is invalid.
	 */
	public function init()
	{
		if (is_string($this->db)) {
			$this->db = Yii::$app->getComponent($this->db);
		}
		if (!$this->db instanceof Connection) {
			throw new InvalidConfigException("DbSession::db must be either a DB connection instance or the application component ID of a DB connection.");
		}
		parent::init();
	}

	/**
	 * Returns a value indicating whether to use custom session storage.
	 * This method overrides the parent implementation and always returns true.
	 * @return boolean whether to use custom storage.
	 */
	public function getUseCustomStorage()
	{
		return true;
	}

	/**
	 * Updates the current session ID with a newly generated one .
	 * Please refer to [[http://php.net/session_regenerate_id]] for more details.
	 * @param boolean $deleteOldSession Whether to delete the old associated session file or not.
	 */
	public function regenerateID($deleteOldSession = false)
	{
		$oldID = session_id();

		// if no session is started, there is nothing to regenerate
		if (empty($oldID)) {
			return;
		}

		parent::regenerateID(false);
		$newID = session_id();

		$query = new Query;
		$row = $query->from($this->sessionTable)
			->where(['id' => $oldID])
			->createCommand($this->db)
			->queryOne();
		if ($row !== false) {
			if ($deleteOldSession) {
				$this->db->createCommand()
					->update($this->sessionTable, ['id' => $newID], ['id' => $oldID])
					->execute();
			} else {
				$row['id'] = $newID;
				$this->db->createCommand()
					->insert($this->sessionTable, $row)
					->execute();
			}
		} else {
			// shouldn't reach here normally
			$this->db->createCommand()
				->insert($this->sessionTable, [
					'id' => $newID,
					'expire' => time() + $this->getTimeout(),
				])->execute();
		}
	}

	/**
	 * Session read handler.
	 * Do not call this method directly.
	 * @param string $id session ID
	 * @return string the session data
	 */
	public function readSession($id)
	{
		$query = new Query;
		$data = $query->select(['data'])
			->from($this->sessionTable)
			->where('[[expire]]>:expire AND [[id]]=:id', [':expire' => time(), ':id' => $id])
			->createCommand($this->db)
			->queryScalar();
		return $data === false ? '' : $data;
	}

	/**
	 * Session write handler.
	 * Do not call this method directly.
	 * @param string $id session ID
	 * @param string $data session data
	 * @return boolean whether session write is successful
	 */
	public function writeSession($id, $data)
	{
		// exception must be caught in session write handler
		// http://us.php.net/manual/en/function.session-set-save-handler.php
		try {
			$expire = time() + $this->getTimeout();
			$query = new Query;
			$exists = $query->select(['id'])
				->from($this->sessionTable)
				->where(['id' => $id])
				->createCommand($this->db)
				->queryScalar();
			if ($exists === false) {
				$this->db->createCommand()
					->insert($this->sessionTable, [
						'id' => $id,
						'data' => $data,
						'expire' => $expire,
					])->execute();
			} else {
				$this->db->createCommand()
					->update($this->sessionTable, ['data' => $data, 'expire' => $expire], ['id' => $id])
					->execute();
			}
		} catch (\Exception $e) {
			if (YII_DEBUG) {
				echo $e->getMessage();
			}
			// it is too late to log an error message here
			return false;
		}
		return true;
	}

	/**
	 * Session destroy handler.
	 * Do not call this method directly.
	 * @param string $id session ID
	 * @return boolean whether session is destroyed successfully
	 */
	public function destroySession($id)
	{
		$this->db->createCommand()
			->delete($this->sessionTable, ['id' => $id])
			->execute();
		return true;
	}

	/**
	 * Session GC (garbage collection) handler.
	 * Do not call this method directly.
	 * @param integer $maxLifetime the number of seconds after which data will be seen as 'garbage' and cleaned up.
	 * @return boolean whether session is GCed successfully
	 */
	public function gcSession($maxLifetime)
	{
		$this->db->createCommand()
			->delete($this->sessionTable, '[[expire]]<:expire', [':expire' => time()])
			->execute();
		return true;
	}
}
