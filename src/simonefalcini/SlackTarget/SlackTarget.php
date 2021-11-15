<?php
/**
 * @link https://www.falcini.com/
 * @copyright Copyright (c) Simone Falcini
 */

namespace simonefalcini\SlackTarget;

use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use \simonefalcini\IpTools\IpTools;

/**
 * SlackTarget records log messages to slack.
 * *
 * @author Simone Falcini
 */
class SlackTarget extends \yii\log\Target
{
	public $channel;
	public $async = true;
	public $username = 'Yii';
	public $hook;
    public $error_max_length = 1000;

	function earlyFatalErrorHandler($unregister = false) {
		    // Functionality for "unregistering" shutdown function
		    static $unregistered;
		    if ($unregister) $unregistered = true;
		    if ($unregistered) return;		    

		    // 1. error_get_last() returns NULL if error handled via set_error_handler
		    // 2. error_get_last() returns error even if error_reporting level less then error
		    $error = error_get_last();

		    // Fatal errors
		    $errorsToHandle = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING;

		    if ((!defined('APP_PRODUCTION_MODE') || APP_PRODUCTION_MODE) && !is_null($error) && ($error['type'] & $errorsToHandle))
		    {
		        $message = 'FATAL ERROR: ' . $error['message'];
		        if (!empty($error['file'])) $message .= ' (' . $error['file'] . ':' . $error['line']. ')';

		        $logs = [
		        	[$message,'error','php',time()]
		        ];
		        $this->processLogs($logs);

		        // Tell customer that you are aware of the issue and will take care of things
		        // echo "Apocalypse now!!!"; 
		    }
		}
	
	/**
	 * Initializes the route.
	 * This method is invoked after the route is created by the route manager.
	 */
	public function init()
	{		
		parent::init();	
		register_shutdown_function([$this,'earlyFatalErrorHandler']);
	}

	/**
     * Send log messages to Slack
     */
    public function export()
    {
        
    	foreach($this->messages as $log) {

    		$error_level = \yii\log\Logger::getLevelName($log[1]);    		

			$trace = null;		
			$remote_ip = null;
			$error_name = '';
			$error_message = '';
			$error_file = '';				
			$error_line = '';
			$text = '';
			$curent_url = '';
			$stack_trace = '';
			$public_ip = '';
			$server_name = '';

			if (is_object($log[0])) {
				$error_name = method_exists($log[0],'getName') ? $log[0]->getName() : '';
				$error_message = $log[0]->getMessage();
				$error_file = $log[0]->getFile();
				$error_line = $log[0]->getLine();
				if (method_exists($log[0],'getTrace')) {
					$trace = $log[0]->getTrace();											
					if (isset($trace[0]['args'][0])) {
						$trace['function'] = $trace[0]['args'][0];
					}					
				}
				/**
				* $log[0] -> Object with error ->getName()
				* $log[1] -> Level
				* $log[2] -> Category (class name)
				* $log[3] -> Timestamp
				*
				* TRACE: $log[0]->getTrace()
				* $trace[0]['file'] -> Filename
				* $trace[0]['line'] -> Line code
				* $trace[0]['args'][0] -> Function error
				*/	
			}
			else {
				if (isset($log[4])) {
					$trace = $log[4];
					if (isset($trace[0])) {
						$error_file = $trace[0]['file'];
						$error_line = $trace[0]['line'];	
					}
				}
				$error_message = $log[0];
				$error_name = $log[2];

				/**
				* $log[0] -> Message
				* $log[1] -> Level (1: error, 2: warning, 4: info, 8: trace) Logger::getLevelName($level)
				* $log[2] -> Category
				* $log[3] -> Timestamp
				* 
				* TRACE: $log[4]
				* $trace[0]['file'] -> Filename
				* $trace[0]['line'] -> Line code
				* $trace[0]['function'] -> Error type
				*/			
			}

			$stack_lenght = 0;
			if (isset($trace) && is_array($trace)) {
				foreach($trace as $stack_element) {
					if (is_array($stack_element) && isset($stack_element['file']) && $stack_lenght++ < 10) {
                        if (!Yii::$app->errorHandler->isCoreFile($stack_element['file'])) {
                            $stack_trace .= "\n".$stack_element['file'].':'.$stack_element['line'];
                        }                        
                    }						
				}
			}
			
			//file_put_contents( Yii::$app->getBasePath() .'/runtime/logs/testing.log' , print_r($log,true) );

    		$error = $log[0];

			if (is_array($error_message)) {
				$error_message = print_r($error_message,true);
			}
		
			// If we are in apache, append some essential http vars							
			if (php_sapi_name() != "cli") {				
				$public_ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
				$remote_ip = IpTools::getIp();
				$remote_geo = IpTools::getGeo($remote_ip);
				$remote_asn = IpTools::getAsn($remote_ip);	
				$remote_asn_name = isset($remote_asn['name']) ? $remote_asn['name'] : '';
				$geocity = IpTools::getGeoCity($remote_ip);
				$remote_city = isset($geocity['city_name']) ? $geocity['city_name'] : '';
				$current_url = Yii::$app->request->getMethod() .': ' .Yii::$app->request->getAbsoluteUrl();
				
				$params = '';
				foreach($_POST as $key => $val) {
					$params.= "\n_".$key."_ (POST): ".print_r($val,true);
				}							
			}
			else {
				$public_ip = '';
				$remote_ip = '';
				$remote_geo = '';
				$remote_asn = '';
				$remote_asn_name = '';
				$geocity = '';
				$remote_city = '';
				$current_url = 'console';
			}

			$blocks = [];
			$blocks[] = [
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => self::getErrorIcon($error_level).' *'.ucwords(strtolower($error_level)).':* '.$error_name,
				],
			];

            $error_message = (string)$error_message;

            if (mb_strlen($error_message) > $this->error_max_length) {
                $blocks[] = [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => mb_substr($error_message,0,$this->error_max_length) . '...',
                    ],
                ];			
            }
            else {              
                $blocks[] = [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $error_message,
                    ],
                ];
            }			

            $blocks[] =	[
			    'type' => 'divider'
			];

			$blocks[] =	[
				'type' => 'section',
				'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*File:* ".$error_file."\n*Row:* ".$error_line."\n`".$current_url."`",
                ]				
			];

            $blocks[] =	[
			    'type' => 'divider'
			];

			$fields = [];

			if (isset($remote_ip)) {
				$fields[] = [
					'type' => 'mrkdwn',
					'text' => "*Client:*\n_ASN:_ ".$remote_asn_name."\n_IP:_ ".$remote_ip."\n_Geo:_ ".$remote_geo."\n_City:_ ".$remote_city,
				];
			}

            $fields[] = [
				'type' => 'mrkdwn',
				'text' => "*Server:*\n_Server IP:_ ".$public_ip,
			];

			$post_data = '';
			if (isset($_POST) && count($_POST) > 0) {
				foreach($_POST as $k => $v) {
					$post_data .= "\n_".$k.":_ ".(is_array($v)?print_r($v,true):$v);
				}

				$fields[] = [
					'type' => 'mrkdwn',
					'text' => "*POST:*\n".$post_data,
				];
			}

			if (isset(Yii::$app->user) && !Yii::$app->user->isGuest) {
				$other_user_data = '';
				$CUSTOM_USER_PARAMS = ['id','name','email'];
				foreach($CUSTOM_USER_PARAMS as $param) {
					if (isset(Yii::$app->user->identity->$param)) {
						$other_user_data .= "\n_".ucwords($param).":_ ".Yii::$app->user->identity->$param;
					}
				}
				
				$fields[] = [
					'type' => 'mrkdwn',
					'text' => "*User:*".$other_user_data,
				];		
			}

			

			$blocks[] = [
				'type' => 'section',
				'fields' => $fields,
			];

            $blocks[] =	[
			    'type' => 'divider'
			];

			$blocks[] =	[
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => "*Stack trace:*\n```".$stack_trace.'```',
				],			
			];

            $data = [
                'channel' => $this->channel,
                'username' => $this->username,
                'blocks' => $blocks,
                'text' => strtoupper($error_level). ': ' . mb_substr((string)$error_message,0,$this->error_max_length),
            ];
    
            if ($this->async) {
                $command = 'curl -X POST --data-urlencode \'payload='.str_replace("'","'\\''",json_encode($data)).'\' '.$this->hook.' > /dev/null 2>&1 &';            
                exec($command);
                echo $command; 
            }
            else {
                $ch = curl_init($this->hook);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_exec($ch);
                curl_close($ch);
            }
			
    	}

	}

    private static function getErrorIcon($error_name) {
        $error_name = strtolower($error_name);
        switch ($error_name) {
            case 'error':
                return ':red_circle:';
            case 'warning':
                return ':warning:';
            case 'notice':
                return ':warning:';
            case 'deprecated':
                return ':warning:';
            case 'strict':
                return ':warning:';
            case 'exception':
                return ':warning:';
            case 'fatal error':
                return ':warning:';
            case 'parse error':
                return ':warning:';
        }
    }
	
}