<?php

class KelioBridge {


	function __construct($db) {

		$this->db = &$db;

	}

	/*
	 * En mode startrek, beyond the star, la recherche est infinie
	 */
	function getTimeFromOuterSpace($date='') {
		global $conf;

		$client = new SoapClient($conf->global->KELIO_SERVICE_URI.'JobTotalService?wsdl'
				, array(
						'login'=> $conf->global->KELIO_WSLD_USER
						,'password' =>  $conf->global->KELIO_WSLD_PASS
						,'trace'=>true
				)
				);

			/*$parameters= array(
				'populationFilter'=>''
				,'groupFilter'=>''
				,'offset'=>0
		);
		try {
			$res = $client->exportActualPeriodicalJobTotals($parameters);
		}
		catch(Exception $e) {
			pre($e,1);

		}*/

		if(empty($date)) $date=date('Y-m-d',strtotime('-1day'));

		$parameters= array(
				'populationFilter'=>''
				,'groupFilter'=>''
				,'date'=>$date
		);
		try {
			$res = $client->exportActualPerpetualJobTotalsListFromDate($parameters);
		}
		catch(Exception $e) {
			pre($e,1);
		}

		$this->_gtfos_parseData($res->exportedPerpetualJobTotals->PerpetualJobTotal);

	}

	private function _gtfos_parseData(&$TData) {
		global $db, $user,$langs;

		$this->errors = array();

		ob_start();
		foreach($TData as &$data) {

			$projectKey = $data->costCentreAbbreviation;
			$userKey = $data->employeeBadgeCode;
			$taskKey = $data->jobCode;

			$import_key =substr( base64_encode($projectKey.$taskKey.$userKey.$data->date), 0 ,14);

			$userTime = $this->_get_user_from_key($userKey,$data);
			$task = $this->_get_task_from_key($projectKey,$taskKey, $data->jobDescription);

			if($task!==false && $userTime!==false) {
				$ret = $task->add_contact($userTime->id, 181,'internal');
				if($ret<0) {
					$this->errors[]='user already ad contact of task '.$task->ref;
					//var_dump($task);exit;

				}

				$task->timespent_date = strtotime($data->date);
				$task->timespent_duration = $data->hours * 3600;
				$task->timespent_fk_user = $userTime->id;
				$task->timespent_note = $langs->trans('TimeFromKelio');

				$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."projet_task_time ";
				$sql.= "WHERE fk_user = ".$task->timespent_fk_user." ";
				$sql.= "AND fk_task = ".$task->id." ";
				$sql.= "AND task_date = '".$data->date."' ";

				$res = $db->query($sql);
				if($obj = $db->fetch_object($res)) {
					$task->timespent_id = $obj->rowid;
					$task->updateTimeSpent($user);
				}
				else{
					$task->addTimeSpent($user);
					$res = $db->query("UPDATE ".MAIN_DB_PREFIX."projet_task_time SET import_key='".$import_key."' WHERE rowid=".$task->timespent_id);

				}
			}
		}

		ob_get_clean();

		if(count($this->errors)>0) {

			var_dump($this->errors);
		}

		echo 1;

	}
	private function _get_task_from_key($projectKey,$taskKey, $label) {

		global $TTask,$TProject,$db, $user;

		dol_include_once('/projet/class/project.class.php');
		dol_include_once('/projet/class/task.class.php');

		$key = $projectKey.'-'.$taskKey;
		if(empty($TTask))$TTask=array();
		if(empty($TProject))$TProject=array();


		if(!isset($TTask[$key])) {

			if(!isset($TProject[$projectKey])) {
				$project=new Project($db);
				$project->fetch(0, $projectKey);

				if($project->id<=0) {
					$this->errors[] = 'unknown project '.$projectKey;
					return false; // on ne trouve pas le projet associé
				}

				$TProject[$projectKey] = $project;
			}

			$project = $TProject[$projectKey];

			$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."projet_task WHERE ref='".$key."'");
			$task=new Task($db);
			if($obj= $db->fetch_object($res)) {

				$task->fetch($obj->rowid);

			}
			else {

				$task=new Task($db);
				$task->fk_project = $project->id;
				$task->ref=$key;
				$task->fk_task_parent = 0;
				$task->date_c = time();
				$task->label =$label;
				$task->description='';
				$task->date_start = $project->date_start;
				$task->date_end = $project->date_end;
				$task->planned_workload = 0;

				$task->progress = 0;

				$res = $task->create($user);
				if($res<0) {

					var_dump($task);exit;
				}

			}

			$TTask[$key] = $task;
		}

		return 	$TTask[$key];


	}

	private function _get_user_from_key($userKey,$data) {
		global $TUser,$db, $user;

		if(empty($TUser))$TUser=array();

		if(!isset($TUser[$userKey])) {
			$res = $db->query("SELECT fk_object FROM ".MAIN_DB_PREFIX."user_extrafields WHERE kelio_badge_id='".$userKey."'");
			if($res===false) {
				var_dump($db);exit;

			}

			if($obj= $db->fetch_object($res)) {

				$u =new User($db);
				$u->fetch($obj->fk_object);

				$TUser[$userKey] = $u;

			}
			else {

				$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE firstname='".$db->escape($data->employeeFirstName)."' AND lastname='".$db->escape($data->employeeSurname)."'");
				if($obj= $db->fetch_object($res)) {

					$u =new User($db);
					$u->fetch($obj->rowid);

					$TUser[$userKey] = $u;

				}
				else {

					$this->errors[] = 'unknown user '.$userKey;

					return false;

				}
			}
		}

		return $TUser[$userKey];

	}

	function getEmployees() {

		global $conf;

		$client = new SoapClient($conf->global->KELIO_SERVICE_URI.'EmployeeService?wsdl'
				, array(
						'login'=> $conf->global->KELIO_WSLD_USER
						,'password' =>  $conf->global->KELIO_WSLD_PASS
						,'trace'=>true
				)
				);

		var_dump($client, $client->__getFunctions());

		$parameters= array(
				'populationFilter'=>''
		);
		try {
			$res = $client->exportEmployees($parameters);
		}
		catch(Exception $e) {
			pre($e,1);

		}
		pre($res,1);
		var_dump($client->__getLastResponse() );
	}

	function getClocking() {
// works !!
		global $conf;

		$client = new SoapClient($conf->global->KELIO_SERVICE_URI.'ClockingService?wsdl'
				, array(
						'login'=> $conf->global->KELIO_WSLD_USER
						,'password' =>  $conf->global->KELIO_WSLD_PASS
						,'trace'=>true
				)
				);

		var_dump($client, $client->__getFunctions());

		$t1day=strtotime('-1day');

		$parameters= array(
				'endDate'=>date('Y-m-d',$t1day)
				,'startDate'=>date('Y-m-d',$t1day)
				,'groupFilter'=>''
				,'populationFilter'=>''
		);
		try {
			$res = $client->exportClockingsByDate($parameters);
		}
		catch(Exception $e) {
			pre($e,1);

		}
		pre($res,1);
		var_dump($client->__getLastResponse() );


	}

}
