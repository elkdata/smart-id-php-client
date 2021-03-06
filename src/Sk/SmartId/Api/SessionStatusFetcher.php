<?php
namespace Sk\SmartId\Api;

use Sk\SmartId\Api\Data\AuthenticationHash;
use Sk\SmartId\Api\Data\SessionEndResultCode;
use Sk\SmartId\Api\Data\SessionStatus;
use Sk\SmartId\Api\Data\SessionStatusCode;
use Sk\SmartId\Api\Data\SessionStatusRequest;
use Sk\SmartId\Api\Data\SignableData;
use Sk\SmartId\Api\Data\SmartIdAuthenticationResponse;
use Sk\SmartId\Exception\DocumentUnusableException;
use Sk\SmartId\Exception\InterruptedException;
use Sk\SmartId\Exception\SessionTimeoutException;
use Sk\SmartId\Exception\TechnicalErrorException;
use Sk\SmartId\Exception\UserRefusedException;

class SessionStatusFetcher
{
  /**
   * @var string
   */
  private $sessionId;

  /**
   * @var SmartIdConnector
   */
  private $connector;

  /**
   * @var SignableData
   */
  private $dataToSign;

  /**
   * @var AuthenticationHash
   */
  private $authenticationHash;

  /**
   * In milliseconds
   * @var int
   */
  private $sessionStatusResponseSocketTimeoutMs;

  /**
   * @param SmartIdConnector $connector
   */
  public function __construct( SmartIdConnector $connector )
  {
    $this->connector = $connector;
  }

  /**
   * @param string $sessionId
   * @return $this
   */
  public function setSessionId( $sessionId )
  {
    $this->sessionId = $sessionId;
    return $this;
  }

  /**
   * @return string
   */
  public function getSessionId()
  {
    return $this->sessionId;
  }

  /**
   * @param SignableData $dataToSign
   * @return $this
   */
  public function setDataToSign( SignableData $dataToSign )
  {
    $this->dataToSign = $dataToSign;
    return $this;
  }

  /**
   * @param AuthenticationHash $authenticationHash
   * @return $this
   */
  public function setAuthenticationHash( AuthenticationHash $authenticationHash )
  {
    $this->authenticationHash = $authenticationHash;
    return $this;
  }

  /**
   * @param int $sessionStatusResponseSocketTimeoutMs
   * @return $this
   */
  public function setSessionStatusResponseSocketTimeoutMs( $sessionStatusResponseSocketTimeoutMs )
  {
    $this->sessionStatusResponseSocketTimeoutMs = $sessionStatusResponseSocketTimeoutMs;
    return $this;
  }

  /**
   * @return string
   */
  private function getDataToSign()
  {
    if ( isset( $this->authenticationHash ) )
    {
      return $this->authenticationHash->getDataToSign();
    }
    return $this->dataToSign->getDataToSign();
  }

  /**
   * @return SmartIdAuthenticationResponse
   */
  public function getAuthenticationResponse()
  {
    $sessionStatus = $this->fetchSessionStatus();
    if ( $sessionStatus->isRunningState() )
    {
      $authenticationResponse = new SmartIdAuthenticationResponse();
      $authenticationResponse->setState( SessionStatusCode::RUNNING );
    }
    else
    {
      $this->validateSessionStatus( $sessionStatus );
      $authenticationResponse = $this->createSmartIdAuthenticationResponse( $sessionStatus );
    }
    return $authenticationResponse;
  }

  /**
   * @return SessionStatus
   */
  public function getSessionStatus()
  {
    $request = $this->createSessionStatusRequest( $this->sessionId );
    return $this->connector->getSessionStatus( $request );
  }

  /**
   * @throws TechnicalErrorException
   * @return SessionStatus|null
   */
  private function fetchSessionStatus()
  {
    try
    {
      /** @var SessionStatus $sessionStatus */
      $sessionStatus = $this->getSessionStatus();
      $this->validateResult( $sessionStatus );
      return $sessionStatus;
    }
    catch ( InterruptedException $e )
    {
      throw new TechnicalErrorException( 'Failed to poll session status: ' . $e->getMessage() );
    }
  }

  /**
   * @param string $sessionId
   * @return SessionStatusRequest
   */
  private function createSessionStatusRequest( $sessionId )
  {
    $request = new SessionStatusRequest( $sessionId );
    if ( $this->sessionStatusResponseSocketTimeoutMs )
    {
      $request->setSessionStatusResponseSocketTimeoutMs( $this->sessionStatusResponseSocketTimeoutMs );
    }
    return $request;
  }

  /**
   * @param SessionStatus $sessionStatus
   * @throws TechnicalErrorException
   */
  private function validateSessionStatus( SessionStatus $sessionStatus )
  {
    if ( $sessionStatus->getSignature() === null )
    {
      throw new TechnicalErrorException( 'Signature was not present in the response' );
    }
    if ( $sessionStatus->getCert() === null )
    {
      throw new TechnicalErrorException( 'Certificate was not present in the response' );
    }
  }

  /**
   * @param SessionStatus $sessionStatus
   * @throws TechnicalErrorException
   * @throws UserRefusedException
   * @throws SessionTimeoutException
   * @throws DocumentUnusableException
   */
  private function validateResult( SessionStatus $sessionStatus )
  {
    if ( $sessionStatus->isRunningState() )
    {
      return;
    }

    $result = $sessionStatus->getResult();
    if ( $result === null )
    {
      throw new TechnicalErrorException( 'Result is missing in the session status response' );
    }

    $endResult = $result->getEndResult();
    if ( strcasecmp( $endResult, SessionEndResultCode::USER_REFUSED ) == 0 )
    {
      throw new UserRefusedException();
    }
    else if ( strcasecmp( $endResult, SessionEndResultCode::TIMEOUT ) == 0 )
    {
      throw new SessionTimeoutException();
    }
    else if ( strcasecmp( $endResult, SessionEndResultCode::DOCUMENT_UNUSABLE ) == 0 )
    {
      throw new DocumentUnusableException();
    }
    else if ( strcasecmp( $endResult, SessionEndResultCode::OK ) != 0 )
    {
      throw new TechnicalErrorException( 'Session status end result is \'' . $endResult . '\'' );
    }
  }

  /**
   * @param SessionStatus $sessionStatus
   * @return SmartIdAuthenticationResponse
   */
  private function createSmartIdAuthenticationResponse( SessionStatus $sessionStatus )
  {
    $sessionResult = $sessionStatus->getResult();
    $sessionSignature = $sessionStatus->getSignature();
    $sessionCertificate = $sessionStatus->getCert();

    $authenticationResponse = new SmartIdAuthenticationResponse();
    $authenticationResponse->setEndResult( $sessionResult->getEndResult() )
        ->setSignedData( $this->getDataToSign() )
        ->setValueInBase64( $sessionSignature->getValue() )
        ->setAlgorithmName( $sessionSignature->getAlgorithm() )
        ->setCertificate( $sessionCertificate->getValue() )
        ->setCertificateLevel( $sessionCertificate->getCertificateLevel() )
        ->setState( $sessionStatus->getState() );
    return $authenticationResponse;
  }
}