<?php
/**
 * @link https://github.com/misaret/nomos-yii2/
 * @copyright Copyright (c) 2014 Vitalii Khranivskyi <misaret@gmail.com>
 * @license LICENSE file
 */

namespace misaret\yii;

use Yii;
use yii\caching\Cache;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use misaret\nomos\Storage;

/**
 * NomosCache extends [[Cache]] by using Nomos Storage as cache engine.
 * ~~~
 * 'cache' => [
 *	'class' => '\misaret\yii\NomosCache',
 *	'servers' => [
 *		['host' => '127.0.0.1', 'port' => 14301],
 *		['host' => '127.0.0.1', 'port' => 14302],
 *	],
 *	'level' => 1,
 *	'subLevel' => 1,
 * ]
 * ~~~
 * @author Vitalii Khranivskyi <misaret@gmail.com>
 */
class NomosCache extends Cache
{
	/**
	 * [
	 *	['host' => '127.0.0.1', 'port' => 14301],
	 *	['host' => '127.0.0.1', 'port' => 14302],
	 * ]
	 * @var array
	 */
	public $servers;
	/**
	 * Timeout for socket operation in seconds
	 * @var integer
	 */
	public $socketTimeout;
	/**
	 * @var integer
	 */
	public $level;
	/**
	 * @var integer
	 */
	public $subLevel;
	/**
	 * Update value life time on get command
	 * @var integer
	 */
	public $renewLifeTime;

	public $keyPrefix = '0';
	public $serializer = false;

	/**
	 * @var Storage
	 */
	private $_storage;

	public function init()
	{
		parent::init();

		$this->_storage = $this->_getStorage();
	}

	private function _getStorage()
	{
		if (!$this->servers)
			throw new InvalidConfigException("NomosCache::servers must be set.");

		$storage = new Storage($this->servers);
		if ($this->socketTimeout)
			$storage->timeout = $this->socketTimeout;

		return $storage;
	}

	private function _log($message, $category, $result)
	{
		if ($result) {
			Yii::info($this->level . ',' . $this->subLevel . ',' .  $message . '; return ' . strlen($result) . ' byte', $category);
		} else {
			Yii::warning($this->level . ',' . $this->subLevel . ',' .  $message . ': return ' . gettype($result), $category);
		}
	}

	/**
	 * Convert $key to hexadecimal string, max length 16
	 * @param string $key
	 * @return string
	 */
	protected function buildKey($key)
	{
		if (!is_scalar($key)) {
			$key = substr(sha1(serialize($key)), -16);
		} elseif (!ctype_xdigit($key) || StringHelper::strlen($key) > 16) {
			$key = substr(sha1($key), -16);
		}

		return $key;
	}

	protected function getValue($key)
	{
		$expire = $this->renewLifeTime ?: 0;
		$result = $this->_storage->get($this->level, $this->subLevel, $key, $expire);
		$this->_log($key . ',' . $expire, __METHOD__, $result);
		return $result;
	}

	protected function setValue($key, $value, $expire)
	{
		$result = $this->_storage->put($this->level, $this->subLevel, $key, $expire, $value);
		$this->_log($key . ',' . $expire, __METHOD__, $result);
		return $result;
	}

	protected function addValue($key, $value, $expire)
	{
		return $this->setValue($key, $value, $expire);
	}

	protected function deleteValue($key)
	{
		$result = $this->_storage->delete($this->level, $this->subLevel, $key);
		$this->_log($key, __METHOD__, $result);
		return $result;
	}

	protected function flushValues()
	{
		throw new NotSupportedException('Nomos Storage not support flushValues method yet.');
		return false;
	}
}
