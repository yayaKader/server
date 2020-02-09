<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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

namespace OC\Core\Controller;

use OC\Authentication\WebAuthn\Manager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use Webauthn\PublicKeyCredentialRequestOptions;

class WebAuthnController extends Controller {

	private const WEBAUTHN_LOGIN = 'webauthn_login';

	/** @var Manager */
	private $webAuthnManger;

	/** @var ISession */
	private $session;
	/**
	 * @var ILogger
	 */
	private $logger;

	public function __construct($appName, IRequest $request, Manager $webAuthnManger, ISession $session, ILogger $logger) {
		parent::__construct($appName, $request);

		$this->webAuthnManger = $webAuthnManger;
		$this->session = $session;
		$this->logger = $logger;
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 * @UseSssion
	 */
	public function startAuthentication(string $uid): JSONResponse {
		$this->logger->debug('Starting WebAuthn login');

		$publicKeyCredentialRequestOptions = $this->webAuthnManger->startAuthentication($uid, $this->request->getServerHost());
		$this->session->set(self::WEBAUTHN_LOGIN, json_encode($publicKeyCredentialRequestOptions));

		return new JSONResponse($publicKeyCredentialRequestOptions);
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 * @UseSession
	 */
	public function finishAuthentication(string $data): JSONResponse {
		$this->logger->debug('Validating WebAuthn login');

		if (!$this->session->exists(self::WEBAUTHN_LOGIN)) {
			$this->logger->debug('Trying to finish WebAuthn login without session data');
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}

		// Obtain the publicKeyCredentialOptions from when we started the registration
		$publicKeyCredentialRequestOptions = PublicKeyCredentialRequestOptions::createFromArray($this->session->get(self::WEBAUTHN_LOGIN));

		$this->webAuthnManger->finishAuthentication($publicKeyCredentialRequestOptions, $data);

		return new JSONResponse([]);
	}
}
