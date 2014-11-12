<?php
/**
* LoadAvg - Server Monitoring & Analytics
* http://www.loadavg.com
*
* Memory Module for LoadAvg
* 
* @version SVN: $Id$
* @link https://github.com/loadavg/loadavg
* @author Karsten Becker
* @copyright 2014 Sputnik7
*
* This file is licensed under the Affero General Public License version 3 or
* later.
*/

class Ssh extends LoadAvg
{
	public $logfile; // Stores the logfile name & path

	/**
	 * __construct
	 *
	 * Class constructor, appends Module settings to default settings
	 *
	 */
	public function __construct()
	{
		$this->setSettings(__CLASS__, parse_ini_file(strtolower(__CLASS__) . '.ini', true));
	}

	/**
	 * logMemoryUsageData
	 *
	 * Retrives data and logs it to file
	 *
	 * @param string $type type of logging default set to normal but it can be API too.
	 * @return string $string if type is API returns data as string
	 *
	 */

	//need to save timestamp with offset
	//so we can add support for journalctl 

	//journalctl _COMM=sshd --since "10:00" --until "11:00"

	//journalctl _COMM=sshd --since "previous" --until "current"

	//nice!!

	public function logData( $type = false )
	{
		$class = __CLASS__;
		$settings = LoadAvg::$_settings->$class;

		$sshdLogFile ['path'] =	$settings['settings']['log_location'];

        //log data variables
        $logData['invalid_user'] = 0;
        $logData['failed_pass'] = 0;
        $logData['accepted'] = 0;

	    //grab the logfile
		$logfile = sprintf($this->logfile, date('Y-m-d'));

		//check if log file exists and see time difference
		//stored in elapsed
		if ( $logfile && file_exists($logfile) )
			$elapsed = time() - filemtime($logfile);
		else
			$elapsed = 0;  //meaning new logfile

		//we need to read offset here
		//grab net latest location and figure out elapsed
		//zero out offset
        $sshdLogFile ['offset'] = 0;
        $sshdLogFile ['timestamp'] = 0;

		$sshlatestElapsed = 0;
		$sshLatestLocation = dirname($logfile) . DIRECTORY_SEPARATOR . '_ssh_latest';


		// basically if sshlatestElapsed is within reasonable limits (logger interval + 20%) 
		// then its from the day before rollover so we can use it to replace regular elapsed
		// which is 0 when there is a new log file

		if (file_exists( $sshLatestLocation )) {
			
			//if we want to add more data to return string we can use eplode below
			$last = explode("|", file_get_contents(  $sshLatestLocation ) );

			$sshdLogFile['offset'] = file_get_contents(  $sshLatestLocation );
			//$sshdLogFile['timestamp'] = file_get_contents(  $sshLatestLocation );

	    	//echo 'STORED OFFSET  : ' . $sshdLogFile['offset']   . "\n";

			$sshlatestElapsed =  ( time() - filemtime($sshLatestLocation));

			//if its a new logfile check to see if whats up with the interval
			if ($elapsed == 0) {

				//data needs to within the logging period limits to be accurate
				$interval = $this->getLoggerInterval();

				if (!$interval)
					$interval = 360;
				else
					$interval = $interval * 1.2;

				if ( $sshlatestElapsed <= $interval ) 
					$elapsed = $sshlatestElapsed;
			}
		}

        // Reset offset if file size has reduced (truncated)
        // means logs have been rotated!
        // TODO :
        // if logs have been rotated we need to look for data in old log file
        // and add to new log file
        // however need to read a .gz to do this as old logs are compressed and soted by date
        // ie secure-20140427.gz
        $fileSize = filesize($sshdLogFile['path']);

        if($fileSize < $sshdLogFile['offset']){
            $sshdLogFile['offset'] = 0;
        }

        //read log file and get log data
        if ( !$this->loadLogData( $sshdLogFile, $logData ) )
        	return false;

		//if we were able to get last data from mysql latest above
		//figure out the difference as thats what we chart
		if (@$sshdLogFile['offset'] && $elapsed) {

			if ($logData['accepted'] < 0) $logData['accepted'] = 0;

			if ($logData['failed_pass'] < 0) $logData['failed_pass'] = 0;

			if ($logData['invalid_user'] < 0) $logData['invalid_user'] = 0 ;

			$string = time() . "|" . $logData['accepted'] . "|" . $logData['failed_pass']  . "|" . $logData['invalid_user']       . "\n";

    		//echo 'DATA WRITE  : ' . $logData['accepted'] . '|' . $logData['failed_pass'] . '|' . $logData['invalid_user'] . "\n";

		} else {
			//if this is the first value in the set and there is no previous data then its null
			
			$lastlogdata = "|0|0|0";

			$string = time() . $lastlogdata . "\n" ;

		}

		//write out log data here
		$this->safefilerewrite($logfile,$string,"a",true);

		// write out filesize so we can pick up where we left off next time around
		$this->safefilerewrite($sshLatestLocation,$fileSize,"w",true);

		if ( $type == "api")
			return $string;
		else
			return true;

	}


	/**
	 * loadLogData
	 *
	 * Loads ssh log data from logfile, formats and parses it to pass it back
	 *
	 * @sshdLogFile log file location and settings
	 * @logData string that contains return data
	 * @return flag for success or fail
	 *
	 */

	function loadLogData( array &$sshdLogFile,  array&$logData )
	{

        // Open log file for reading
        $f = @fopen($sshdLogFile['path'],"r");
        if($f) {
            // Seek to last position we know
            fseek($f, $sshdLogFile['offset']);

            // Read new lines until end of file
            while(!feof($f)) {
                // Read line
                $line = @fgets($f,4096);

                if($line !== false) {

                    $line = trim($line);

                    // We check only lines with "sshd"
                    if(preg_match("/sshd/", $line)) {

                    	//failed passwords
                        if(preg_match("/Failed password/", $line)) 
                            $logData['failed_pass'] += 1;

                        //invalid users
                        if(preg_match("/Invalid user/", $line)) 
                            $logData['invalid_user'] += 1;

                        if(preg_match("/ROOT LOGIN REFUSED/", $line)) 
                            $logData['invalid_user'] += 1;

                        //accepted password issues
                        if(preg_match("/Accepted password/", $line)) 
                            $logData['accepted'] += 1;

                        if(preg_match("/Accepted publickey/", $line)) 
                            $logData['accepted'] += 1;

                        
                    }
                }
                // Sleep for 1 microsecond (so that we don't take all CPU resources 
                // and leave small part for other processes in case we need to parse a lot of data
                usleep(1);
            } 

            // Get current offset
            $currentOffset = ftell($f);

            //update the offest here
            if($sshdLogFile['offset'] != $currentOffset)
                $sshdLogFile['offset'] = $currentOffset;
          
            // Close file
            @fclose($f);

        } else { 

        	//cant open logfile - clean up and return
            @fclose($f);
            return false;
        }

        /*
        echo "\n";
        echo "------------------------------------- \n";
        echo 'INVALID USER:' . $logData['invalid_user'] . "\n" ;
        echo 'FAILED PASS :' . $logData['failed_pass'] . "\n" ;
        echo 'ACCEPTED    :' . $logData['accepted'] . "\n" ;
        echo 'OFFSET      :' . $sshdLogFile['offset'] . "\n" ;
        echo "------------------------------------- \n";
        echo "\n";
		*/

        return true;

	}

	/**
	 * getData
	 *
	 * Gets data from logfile, formats and parses it to pass it to the chart generating function
	 *
	 * @param string $switch with switch data to populate return array
	 * @return array $return data retrived from logfile
	 *
	 */


	public function getUsageData( $logfileStatus)
	{
		$class = __CLASS__;
		$settings = LoadAvg::$_settings->$class;

		$contents = null;

		$replaceDate = self::$current_date;
		
		if ($logfileStatus == false ) {
		
			if ( LoadAvg::$period ) {
				$dates = self::getDates();
				foreach ( $dates as $date ) {
					if ( $date >= self::$period_minDate && $date <= self::$period_maxDate ) {
						$this->logfile = str_replace($replaceDate, $date, $this->logfile);
						$replaceDate = $date;
						if ( file_exists( $this->logfile ) )
							$contents .= file_get_contents($this->logfile);
					}
				}
			} else {
				$contents = file_get_contents($this->logfile);
			}

		} else {

			$contents = 0;
		}

		if ( strlen($contents) > 1 ) {
			$contents = explode("\n", $contents);
			$return = $usage = $args = array();

			$swap = array();
			$usageCount = array();
			$dataArray = $dataArrayOver = $dataArraySwap = array();

			if ( LoadAvg::$_settings->general['chart_type'] == "24" ) $timestamps = array();

			$chartArray = array();

			$this->getChartData ($chartArray, $contents);

			$totalchartArray = (int)count($chartArray);
				
			//data[0] = time
			//data[1] = accepted 
			//data[2] = failed_pass
			//data[3] = invalid_user

			//$displayMode =	$settings['settings']['display_limiting'];

			for ( $i = 0; $i < $totalchartArray; ++$i) {				
				$data = $chartArray[$i];

				//echo '<pre>DATA: ' . print_r($data) .  "</pre>";

				// clean data for missing values and cleanup
				//make this a function 
				$redline = ($data[1] == "-1" ? true : false);

				if ($redline) {
					$data[1]=0.0;
					$data[2]=0.0;
					$data[3]=0.0;
				}

				//clean main dataset
				if (  (!$data[1]) ||  ($data[1] == null) || ($data[1] == "")  )
					$data[1]=0.0;

				//used to filter out redline data from usage data as it skews it
				if (!$redline) 
					$usage[] = ( $data[1]  );
				
			
				$timedata = (int)$data[0];
				$time[( $data[1]  )] = date("H:ia", $timedata);

				$usageCount[] = ($data[0]*1000);

				if ( LoadAvg::$_settings->general['chart_type'] == "24" ) 
					$timestamps[] = $data[0];

				// display data accepted
				$dataArray[$data[0]] = "[". ($data[0]*1000) .", ". ( $data[1]  ) ."]";
				
				// display data failed
				$dataArrayOver[$data[0]] = "[". ($data[0]*1000) .", ". ( $data[2]  ) ."]";
				
				// display data invalid user
				$dataArrayOver_2[$data[0]] = "[". ($data[0]*1000) .", ". ( $data[3]  ) ."]";

			}

			//need totoals for
			// accepted, failed and user
			//not high and low ?

			$mem_high = max($usage);
			$mem_low  = min($usage); 
			$mem_mean = array_sum($usage) / count($usage);

			//really needs to be max across data 1, data 2 and data 3
			$ymax = $mem_high;
			$ymin = $mem_low;

			
			$mem_high_time = $time[max($usage)];
			$mem_low_time = $time[min($usage)];
			$mem_latest = ( ( $usage[count($usage)-1]  )  )    ;		

			if ( LoadAvg::$_settings->general['chart_type'] == "24" ) {
				end($timestamps);
				$key = key($timestamps);
				$endTime = strtotime(LoadAvg::$current_date . ' 24:00:00');
				$lastTimeString = $timestamps[$key];
				$difference = ( $endTime - $lastTimeString );
				$loops = ( $difference / 300 );

				for ( $appendTime = 0; $appendTime <= $loops; $appendTime++ ) {
					$lastTimeString = $lastTimeString + 300;
					$dataArray[$lastTimeString] = "[". ($lastTimeString*1000) .", 0]";
				}
			}
		
			// values used to draw the legend
			$variables = array(
				'mem_high' => $mem_high,
				'mem_high_time' => $mem_high_time,
				'mem_low' => $mem_low,
				'mem_low_time' => $mem_low_time,
				'mem_mean' => $mem_mean,
				'mem_latest' => $mem_latest,
				'mem_swap' => $swap
			);
		
			// get legend layout from ini file
			$return = $this->parseInfo($settings['info']['line'], $variables, __CLASS__);

			if (count($dataArrayOver) == 0) { $dataArrayOver = null; }
			if ( count($dataArrayOver_2) == 0 ) $dataArrayOver_2 = null;

			ksort($dataArray);
			if (!is_null($dataArrayOver)) ksort($dataArrayOver);
			if (!is_null($dataArrayOver_2)) ksort($dataArrayOver_2);

			$dataString = "[" . implode(",", $dataArray) . "]";
			$dataOverString = is_null($dataArrayOver) ? null : "[" . implode(",", $dataArrayOver) . "]";
			$dataOverString_2 = is_null($dataArrayOver_2) ? null : "[" . implode(",", $dataArrayOver_2) . "]";

			$return['chart'] = array(
				'chart_format' => 'line',
				'ymin' => $ymin,
				'ymax' => $ymax,
				'xmin' => date("Y/m/d 00:00:01"),
				'xmax' => date("Y/m/d 23:59:59"),
				'mean' => $mem_mean,
				'dataset_1' => $dataString,
				'dataset_1_label' => 'Accepted',

				'dataset_2' => $dataOverString,
				'dataset_2_label' => 'Failed',

				'dataset_3' => $dataOverString_2,
				'dataset_3_label' => 'Invalid User',

				'overload' => $settings['settings']['overload']
			);

			return $return;	
		} else {

			return false;	
		}
	}


	/**
	 * genChart
	 *
	 * Function witch passes the data formatted for the chart view
	 *
	 * @param array @moduleSettings settings of the module
	 * @param string @logdir path to logfiles folder
	 *
	 */


	public function genChart($moduleSettings, $logdir)
	{
		$charts = $moduleSettings['chart']; //contains args[] array from modules .ini file

		$module = __CLASS__;
		$i = 0;
		foreach ( $charts['args'] as $chart ) {
			$chart = json_decode($chart);

			//grab the log file for current date (current date can be overriden to show other dates)
			$this->logfile = $logdir . sprintf($chart->logfile, self::$current_date);

			// find out main function from module args that generates chart data
			// in this module its getData above
			$caller = $chart->function;

			//check if function takes settings via GET url_args 
			$functionSettings =( (isset($moduleSettings['module']['url_args']) && isset($_GET[$moduleSettings['module']['url_args']])) ? $_GET[$moduleSettings['module']['url_args']] : '2' );

			if ( file_exists( $this->logfile )) {
				$i++;				
				$logfileStatus = false;

				//call modules main function and pass over functionSettings
				if ($functionSettings) {
					$stuff = $this->$caller( $logfileStatus, $functionSettings );
				} else {
					$stuff = $this->$caller( $logfileStatus );
				}

			} else {
				//no log file so draw empty charts
				$i++;				
				$logfileStatus = true;
			}

			//now draw chart to screen
			include APP_PATH . '/views/chart.php';
		}
	}
}

