<?php

namespace OCA\OJSXC\Controller;

use OCA\OJSXC\Db\Presence;
use OCA\OJSXC\Db\PresenceMapper;
use OCA\OJSXC\Db\StanzaMapper;
use OCA\OJSXC\Exceptions\TerminateException;
use OCA\OJSXC\Http\XMPPResponse;
use OCA\OJSXC\ILock;
use OCA\OJSXC\NewContentContainer;
use OCA\OJSXC\StanzaHandlers\IQ;
use OCA\OJSXC\StanzaHandlers\Message;
use OCA\OJSXC\StanzaHandlers\Presence as PresenceHandler;
use OCA\OJSXC\StanzaLogger;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ILogger;
use OCP\IRequest;
use Sabre\Xml\Reader;
use Sabre\Xml\LibXMLException;

/**
 * Class HttpBindController
 *
 * @package OCA\OJSXC\Controller
 */
class HttpBindController extends Controller
{
	const MESSAGE = 0;
	const IQ = 1;
	const PRESENCE = 2;
	const BODY = 2;


	/**
	 * @var string $userId
	 */
	private $userId;

	/**
	 * @var StanzaMapper OCA\OJSXC\Db\StanzaMapper
	 */
	private $stanzaMapper;

	/**
	 * @var XMPPResponse
	 */
	private $response;

	/**
	 * @var IQ
	 */
	private $iqHandler;

	/**
	 * @var Message
	 */
	private $messageHandler;

	/**
	 * @var Body request body
	 */
	private $body;

	/**
	 * @var SleepTime
	 */
	private $sleepTime;

	/**
	 * @var SleepTime
	 */
	private $maxCicles;

	/**
	 * @var ILock
	 */
	private $lock;

	/**
	 * @var PresenceHandler $presenceHandler
	 */
	private $presenceHandler;

	/**
	 * @var PresenceMapper $presenceMapper
	 */
	private $presenceMapper;

	/**
	 * @var NewContentContainer $newContentContainer
	 */
	private $newContentContainer;

	/**
	 * @var StanzaLogger
	 */
	private $stanzaLogger;

	/**
	 * HttpBindController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param string $userId
	 * @param StanzaMapper $stanzaMapper
	 * @param IQ $iqHandler
	 * @param Message $messageHandler
	 * @param string $host
	 * @param ILock $lock
	 * @param ILogger $logger
	 * @param PresenceHandler $presenceHandler
	 * @param PresenceMapper $presenceMapper
	 * @param string $body
	 * @param int $sleepTime
	 * @param int $maxCicles
	 * @param NewContentContainer $newContentContainer
	 */
	public function __construct(
		$appName,
		IRequest $request,
		$userId,
		StanzaMapper $stanzaMapper,
		IQ $iqHandler,
		Message $messageHandler,
		$host,
		ILock $lock,
		ILogger $logger,
		PresenceHandler $presenceHandler,
		PresenceMapper $presenceMapper,
		$body,
		$sleepTime,
		$maxCicles,
		NewContentContainer $newContentContainer,
		StanzaLogger $stanzaLogger
	) {
		parent::__construct($appName, $request);
		$this->userId = $userId;
		$this->stanzaMapper = $stanzaMapper;
		$this->iqHandler = $iqHandler;
		$this->messageHandler = $messageHandler;
		$this->body = $body;
		$this->sleepTime = $sleepTime;
		$this->maxCicles = $maxCicles;
		$this->response = new XMPPResponse($stanzaLogger);
		$this->lock = $lock;
		$this->presenceHandler = $presenceHandler;
		$this->presenceMapper = $presenceMapper;
		$this->newContentContainer = $newContentContainer;
		$this->stanzaLogger = $stanzaLogger;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @return XMPPResponse
	 */
	public function index()
	{
		$this->lock->setLock();
		$this->presenceMapper->updatePresence();
		$input = $this->body;
		$longpoll = true; // set to false when the response should directly be returned and no polling should be done
		$longpollStart = true; // start the first long poll cycle
		try {
			if (!empty($input)) {
				// replace invalid XML by valid XML one
				$input = str_replace("<vCard xmlns='vcard-temp'/>", "<vCard xmlns='jabber:vcard-temp'/>", $input);
				$reader = new Reader();
				$reader->xml($input);
				$reader->elementMap = [
					'{jabber:client}message' => 'Sabre\Xml\Element\KeyValue',
					'{jabber:client}presence' => function (Reader $reader) {
						return Presence::createFromXml($reader, $this->userId);
					}
				];
				$parsedInput = null;
				try {
					$parsedInput = $reader->parse();
				} catch (LibXMLException $e) {
				}
				if (!is_null($parsedInput)
					&& is_array($parsedInput['value'])
					&& count($parsedInput['value']) > 0) {
					$this->stanzaLogger->logRaw($input, StanzaLogger::RECEIVING);

					$stanzas = $parsedInput['value'];
					foreach ($stanzas as $stanza) {
						$stanzaType = $this->getStanzaType($stanza);
						if ($stanzaType === self::MESSAGE) {
							$this->messageHandler->handle($stanza);
						} elseif ($stanzaType === self::IQ) {
							$result = $this->iqHandler->handle($stanza);
							if (!is_null($result)) {
								$longpoll = false;
								$this->response->write($result);
							}
						} elseif ($stanza['value'] instanceof Presence) {
							$results = $this->presenceHandler->handle($stanza['value']);
							if (!is_null($results) && is_array($results)) {
								$longpoll = false;
								$longpollStart = false;
								foreach ($results as $r) {
									$this->response->write($r);
								}
							}
						}
					}
				}
			}
		} catch (TerminateException $e) {
			$this->response->terminate();
			return $this->response;
		}

		// Start long polling
		$this->presenceMapper->setActive($this->userId);
		if ($this->newContentContainer->getCount() > 0) {
			foreach ($this->newContentContainer->getStanzas() as $stanz) {
				$this->response->write($stanz);
			}
			$longpoll = false; // make sure we poll only one times for the fastes reponse
		}
		$recordFound = false;
		$cicles = 0;
		if ($longpollStart) {
			do {
				try {
					$cicles++;
					$stanzas = $this->stanzaMapper->findByTo($this->userId);
					foreach ($stanzas as $stanz) {
						$this->response->write($stanz);
					}
					$recordFound = true;
				} catch (DoesNotExistException $e) {
					sleep($this->sleepTime);
					$recordFound = false;
				}
			} while ($recordFound === false && $cicles < $this->maxCicles && $longpoll && $this->lock->stillLocked());
		}

		return $this->response;
	}

	/**
	 * @param $stanza
	 * @return int
	 * @codeCoverageIgnore
	 */
	private function getStanzaType($stanza)
	{
		switch ($stanza['name']) {
			case '{jabber:client}message':
				return self::MESSAGE;
			case '{jabber:client}iq':
				return self::IQ;
			case '{jabber:client}presence':
				return self::PRESENCE;
		}
	}
}
