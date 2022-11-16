<?php
/*
 * Arquivo: /jibri_uploader/index.php
 * Projeto: Plataforma Médico Online
 * Versão: 1.7.0
 * Data da criação: Monday, November 14th 2022, 1:31:07 pm
 * Autor: Giovanne Oliveira
 * -----
 * Ultima edição: Wed Nov 16 2022
 * Editado por: Giovanne Oliveira
 * -----
 * Copyright (c) 2022 Plataforma Medico Online - JVM Serviços Médicos Ltda
 * -----
 * Este software, bem como todo o seu código fonte e assets são protegidos por Direitos Autorais. Distribuição proibída.
 * https://medicoonline.med.br
 * -----
 */

require_once('vendor/autoload.php');

use GuzzleHttp\Psr7;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logglyKey = $_ENV['LOGGLY_KEY'] ?? '';
$slackWebhook = $_ENV['SLACK_WEBHOOK_URL'] ?? '';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$log = new Logger('jibri_uploader');
$log->pushHandler(new StreamHandler(__DIR__.'/../logs/jibri_uploader.log', \Monolog\Logger::DEBUG));
$log->pushHandler(new \Monolog\Handler\LogglyHandler($_ENV['LOGGLY_KEY'], \Monolog\Logger::DEBUG));
$log->pushHandler(new \Monolog\Handler\SlackWebhookHandler($_ENV['SLACK_WEBHOOK_URL'], \Monolog\Logger::ERROR));

$log->info('Jibri Uploader started', ['args' => $argv]);


$recordingPath = $argc > 1 ? $argv[1] : null;

if ($recordingPath == null) {
    $log->error('No recording path provided', ['args' => $argv]);
    echo "No recording path provided";
    exit(1);
}

$recordingPath = realpath($recordingPath);

if (!file_exists($recordingPath)) {
    $log->error('Recording path does not exist', ['args' => $argv, 'recordingPath' => $recordingPath]);
    echo "Recording path does not exist";
    exit(1);
}

$metadata = json_decode(file_get_contents($recordingPath . '/metadata.json'), true);

if ($metadata == null) {
    $log->error('No metadata found', ['args' => $argv, 'recordingPath' => $recordingPath]);
    echo "No metadata found";
    exit(1);
}

$uploadEndpoint = $metadata['participants'][0]['group'];

if ($uploadEndpoint == null) {
    $log->error('No upload endpoint found', ['args' => $argv, 'recordingPath' => $recordingPath, 'metadata' => $metadata]);
    echo "No upload endpoint found";
    exit(1);
}

$videoFileName = glob($recordingPath . '/*.mp4')[0];

if ($videoFileName == null) {
    $log->error('No video file found', ['args' => $argv, 'recordingPath' => $recordingPath, 'metadata' => $metadata]);
    echo "No video file found";
    exit(1);
}


$client = new Client();

try {
    $log->debug('Uploading video', ['args' => $argv, 'recordingPath' => $recordingPath, 'metadata' => $metadata, 'videoFileName' => $videoFileName, 'uploadEndpoint' => $uploadEndpoint]);
    $request = $client->request('POST', $uploadEndpoint, [
        'multipart' => [
            [
                'name'     => 'file',
                'contents' => Psr7\Utils::tryFopen($videoFileName, 'r'),
                'filename' => 'recording.mp4'
            ]
        ],
        'http_errors' => false
    ]);

    $response = json_decode($request->getBody()->getContents());

    if ($response->status == 200) {
        $log->info('Video uploaded', ['args' => $argv, 'recordingPath' => $recordingPath, 'metadata' => $metadata, 'videoFileName' => $videoFileName, 'uploadEndpoint' => $uploadEndpoint]);
        echo "Upload successful";
        exit(0);
    }
} catch (Exception $e) {
    $log->critical('Error uploading video', ['args' => $argv, 'recordingPath' => $recordingPath, 'metadata' => $metadata, 'videoFileName' => $videoFileName, 'uploadEndpoint' => $uploadEndpoint, 'error' => $e->getMessage()]);
    $response = json_decode($request->getBody()->getContents());
    echo "Upload failed";
    exit(0);
}
