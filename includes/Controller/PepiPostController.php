<?php
namespace YaySMTP\Controller;

use YaySMTP\Helper\LogErrors;
use YaySMTP\Helper\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class PepiPostController {
	private $smtpObj = 'pepipost';
	private $headers = array();
	private $body    = array();
	private $smtper;

	public function __construct( $phpmailer ) {
		// Set wp_mail_from && wp_mail_from_name - start
		$currentFromEmail = Utils::getCurrentFromEmail();
		$currentFromName  = Utils::getCurrentFromName();
		$from_email       = apply_filters( 'wp_mail_from', $currentFromEmail );
		$from_name        = apply_filters( 'wp_mail_from_name', $currentFromName );
		if ( Utils::getForceFromEmail() == 1 ) {
			$from_email = $currentFromEmail;
		}
		if ( Utils::getForceFromName() == 1 ) {
			$from_name = $currentFromName;
		}
		$phpmailer->setFrom( $from_email, $from_name, false );
		// Set wp_mail_from && wp_mail_from_name - end

		$this->smtper                  = $phpmailer;
		$this->headers['content-type'] = 'application/json';
		$this->headers['api_key']      = $this->getApiKey();

		// $headers = $phpmailer->getCustomHeaders();
		// foreach ($headers as $head) {
		//   $nameHead = isset($head[0]) ? $head[0] : false;
		//   $valueHead = isset($head[1]) ? $head[1] : false;
		//   if (empty($nameHead)) {
		//     $headersData = isset($this->body['headers']) ? (array) $this->body['headers'] : array();
		//     $headersData[$nameHead] = $valueHead;

		//     $this->body = array_merge($this->body, array('headers' => $headersData));
		//   }
		// }

		$this->body = array_merge( $this->body, array( 'subject' => $phpmailer->Subject ) );

		if ( ! empty( $phpmailer->FromName ) ) {
			$dataFrom['name'] = $phpmailer->FromName;
		}
		$dataFrom['email'] = $phpmailer->From; //'confirmation@pepisandbox.com';

		$this->body = array_merge( $this->body, array( 'from' => $dataFrom ) );

		$dataRecips  = array();
		$toAddresses = $phpmailer->getToAddresses();
		if ( ! empty( $toAddresses ) && is_array( $toAddresses ) ) {
			$dataRecips['to'] = array();
			foreach ( $toAddresses as $toEmail ) {
				$address        = isset( $toEmail[0] ) ? $toEmail[0] : false;
				$name           = isset( $toEmail[1] ) ? $toEmail[1] : false;
				$arrTo          = array();
				$arrTo['email'] = $address;
				if ( ! empty( $name ) ) {
					$arrTo['name'] = $name;
				}
				$dataRecips['to'][] = $arrTo;
			}
		}

		$ccAddresses = $phpmailer->getCcAddresses();
		if ( ! empty( $ccAddresses ) && is_array( $ccAddresses ) ) {
			$dataRecips['cc'] = array();
			foreach ( $ccAddresses as $ccEmail ) {
				$address        = isset( $ccEmail[0] ) ? $ccEmail[0] : false;
				$name           = isset( $ccEmail[1] ) ? $ccEmail[1] : false;
				$arrCc          = array();
				$arrCc['email'] = $address;
				if ( ! empty( $name ) ) {
					$arrCc['name'] = $name;
				}
				$dataRecips['cc'][] = $arrCc;
			}
		}

		$bccAddresses = $phpmailer->getBccAddresses();
		if ( ! empty( $bccAddresses ) && is_array( $bccAddresses ) ) {
			$dataRecips['bcc'] = array();
			foreach ( $bccAddresses as $bccEmail ) {
				$address         = isset( $bccEmail[0] ) ? $bccEmail[0] : false;
				$name            = isset( $bccEmail[1] ) ? $bccEmail[1] : false;
				$arrBcc          = array();
				$arrBcc['email'] = $address;
				if ( ! empty( $name ) ) {
					$arrBcc['name'] = $name;
				}
				$dataRecips['bcc'][] = $arrBcc;
			}
		}

		// Attachments
		$attachments = $phpmailer->getAttachments();
		if ( is_array( $attachments ) ) {
			$dataRecips['attachments'] = array();
			foreach ( $attachments as $k => $attachment ) {
				$dataRecips['attachments'][] = array(
					'name'    => $attachment[7],
					'content' => $phpmailer->encodeString( \file_get_contents( $attachment[0] ) ),
				);
			}
		}

		if ( ! empty( $dataRecips ) ) {
			$this->body = array_merge( $this->body, array( 'personalizations' => array( $dataRecips ) ) );
		}

		if ( 'text/plain' === $phpmailer->ContentType ) {
			$content              = $phpmailer->Body;
			$dataContent['type']  = 'amp-content';
			$dataContent['value'] = $content;
			$this->body           = array_merge( $this->body, array( 'content' => array( $dataContent ) ) );
		} else {
			$content     = array(
				'text' => $phpmailer->AltBody,
				'html' => $phpmailer->Body,
			);
			$dataContent = array();
			foreach ( $content as $type => $body ) {
				if ( empty( $body ) ) {
					continue;
				}

				$ctype = $type;
				if ( 'html' !== $type ) {
					$ctype = 'amp-content';
				}

				$dataContent[] = array(
					'type'  => $ctype,
					'value' => $body,
				);
			}

			$this->body = array_merge( $this->body, array( 'content' => $dataContent ) );
		}

		// Reply to
		$replyToAddresses = $phpmailer->getReplyToAddresses();
		if ( ! empty( $replyToAddresses ) ) {
			$emailReplyTo = array_shift( $replyToAddresses );
			if ( ! empty( $emailReplyTo ) && is_array( $emailReplyTo ) ) {
				$addrReplyTo = isset( $emailReplyTo[0] ) ? $emailReplyTo[0] : false;
				if ( ! empty( $addrReplyTo ) && filter_var( $addrReplyTo, FILTER_VALIDATE_EMAIL ) ) {
					$this->body = array_merge( $this->body, array( 'reply_to' => $addrReplyTo ) );
				}
			}
		}

	}

	public function getApiKey() {
		$apiKey          = '';
		$yaysmtpSettings = Utils::getYaySmtpSetting();
		if ( ! empty( $yaysmtpSettings ) && is_array( $yaysmtpSettings ) ) {
			if ( ! empty( $yaysmtpSettings[ $this->smtpObj ] ) && ! empty( $yaysmtpSettings[ $this->smtpObj ]['api_key'] ) ) {
				$apiKey = $yaysmtpSettings[ $this->smtpObj ]['api_key'];
			}
		}
		return $apiKey;
	}

	public function send() {
		$apiLink = 'https://api.pepipost.com/v5/mail/send';

		$response = wp_safe_remote_post(
			$apiLink,
			array(
				'httpversion' => '1.1',
				'blocking'    => true,
				'headers'     => $this->headers,
				'body'        => wp_json_encode( $this->body ),
				'timeout'     => ini_get( 'max_execution_time' ) ? (int) ini_get( 'max_execution_time' ) : 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$errors = $response->get_error_messages();
			foreach ( $errors as $error ) {
				LogErrors::setErr( $error );
			}
			return;
		}

		$emailTo     = array();
		$toAddresses = $this->smtper->getToAddresses();
		if ( ! empty( $toAddresses ) && is_array( $toAddresses ) ) {
			foreach ( $toAddresses as $toEmail ) {
				if ( ! empty( $toEmail[0] ) ) {
					$emailTo[] = $toEmail[0];
				}
			}
		}

		if ( $this->smtper->ContentType ) {
			$dataLogsDB = array(
				'subject'      => $this->smtper->Subject,
				'email_from'   => $this->smtper->From,
				'email_to'     => $emailTo, // require is array
				'mailer'       => 'Pepipost',
				'date_time'    => current_time( 'mysql' ),
				'status'       => 0, // 0: false, 1: true, 2: waiting
				'content_type' => $this->smtper->ContentType,
				'body_content' => $this->smtper->Body,
			);
		}

		$sent = false;
		if ( ! empty( $response['response'] ) && ! empty( $response['response']['code'] ) ) {
			$code        = (int) $response['response']['code'];
			$codeSucArrs = array( 200, 201, 202, 203, 204, 205, 206, 207, 208, 300, 301, 302, 303, 304, 305, 306, 307, 308 );
			if ( ! in_array( $code, $codeSucArrs ) && ! empty( $response['response'] ) ) {
				$error   = $response['response'];
				$message = '';
				if ( ! empty( $error ) ) {
					$message = '[' . $error['code'] . ']: ' . $error['message'];
				}
				LogErrors::clearErr();
				LogErrors::setErr( 'Mailer: Pepipost' );
				LogErrors::setErr( $message );

				$dataLogsDB['date_time']    = current_time( 'mysql' );
				$dataLogsDB['reason_error'] = $message;
				Utils::insertEmailLogs( $dataLogsDB );
			} else {
				$sent = true;
				LogErrors::clearErr();

				$dataLogsDB['date_time'] = current_time( 'mysql' );
				$dataLogsDB['status']    = 1;
				Utils::insertEmailLogs( $dataLogsDB );
			}
		}
		return $sent;
	}
}
