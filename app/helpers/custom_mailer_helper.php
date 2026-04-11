<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendEmail($recipientData = NULL, $subject = NULL, $dataBody = NULL, $attachment = NULL)
{
    return sendUsingMailer($recipientData, $subject, $dataBody, $attachment);
}

// Sent Using PHPMAILER / Default
function sendUsingMailer($recipientData = NULL, $subject = NULL, $dataBody = NULL, $attachment = NULL)
{
    global $config;

    $recipientData = is_array($recipientData) ? $recipientData : [];
    $primaryEmail = trim((string) ($recipientData['recipient_email'] ?? ''));
    $primaryName = trim((string) ($recipientData['recipient_name'] ?? ''));
    $subject = (string) ($subject ?? '');
    $dataBody = (string) ($dataBody ?? '');

    if ($primaryEmail === '' || filter_var($primaryEmail, FILTER_VALIDATE_EMAIL) === false) {
        return ['success' => false, 'message' => 'Invalid recipient email address'];
    }

    // Create an instance; passing `true` enables exceptions
    $mail = new PHPMailer(true);

    try {

        // Server settings
        if (filter_var($config['mail']['debug'], FILTER_VALIDATE_BOOLEAN)) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output
        }

        if ($config['mail']['driver'] === 'smtp') {
            $mail->isSMTP();                                    // Send using SMTP
            $mail->SMTPAuth   = true;                           // Enable SMTP authentication
        }

        $mail->Host       = $config['mail']['host'];           // Set the SMTP server to send through
        $mail->Username   = $config['mail']['username'];       // SMTP username
        $mail->Password   = $config['mail']['password'];       // SMTP password
        $mail->SMTPSecure = $config['mail']['encryption'];     // Enable implicit TLS encryption
        $mail->Port       = $config['mail']['port'];           // TCP port to connect to

        // Recipients
        $mail->setFrom($config['mail']['from_email'], $config['mail']['from_name']);
        $mail->addAddress($primaryEmail, $primaryName); // Add a recipient

        // Add a CC recipient
        if (array_key_exists("recipient_cc", $recipientData) && hasData($recipientData['recipient_cc'])) {
            $ccs = $recipientData['recipient_cc'];
            if (is_array($ccs)) {
                foreach ($ccs as $cc) {
                    $cc = trim((string) $cc);
                    if (filter_var($cc, FILTER_VALIDATE_EMAIL) !== false) {
                        $mail->addCC($cc);
                    }
                }
            } else {
                $ccs = trim((string) $ccs);
                if (filter_var($ccs, FILTER_VALIDATE_EMAIL) !== false) {
                    $mail->addCC($ccs);
                }
            }
        }

        // Add a BCC recipient
        if (array_key_exists("recipient_bcc", $recipientData) && hasData($recipientData['recipient_bcc'])) {
            $bccs = $recipientData['recipient_bcc'];
            if (is_array($bccs)) {
                foreach ($bccs as $bcc) {
                    $bcc = trim((string) $bcc);
                    if (filter_var($bcc, FILTER_VALIDATE_EMAIL) !== false) {
                        $mail->addBCC($bcc);
                    }
                }
            } else {
                $bccs = trim((string) $bccs);
                if (filter_var($bccs, FILTER_VALIDATE_EMAIL) !== false) {
                    $mail->addBCC($bccs);
                }
            }
        }

        // Content
        $mail->isHTML(true); //Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $dataBody;

        if (!empty($attachment)) {
            if (is_array($attachment)) {
                foreach ($attachment as $files) {
                    if (is_string($files) && security()->canReadPath($files))
                        $mail->addAttachment($files);
                }
            } else {
                if (is_string($attachment) && security()->canReadPath($attachment))
                    $mail->addAttachment($attachment);
            }
        }

        if ($mail->send()) {
            $response =  ['success' => true, 'message' => 'Email sent successfully'];
        } else {
            $response =  ['success' => false, 'message' => 'Email unable to sent'];
        }
    } catch (\Exception $e) {
        // die("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        $response = ['success' => false, 'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}"];
    }

    return $response;
}

function replaceTextWithData($string = NULL, $arrayOfStringToReplace = array())
{
	$dataToReplace = arrayDataReplace($arrayOfStringToReplace);
	return str_replace(array_keys($dataToReplace), array_values($dataToReplace), $string);
}

function arrayDataReplace($data)
{
    $newKey = $newValue = $newData = [];
    foreach ($data as $key => $value) {
        array_push($newKey, '%' . $key . '%');
        array_push($newValue, $value);
    }

    foreach ($newKey as $key => $data) {
        $newData[$data] = $newValue[$key];
    }

    return $newData;
}