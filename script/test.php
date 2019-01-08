<?php

	require '../config.php';

	ini_set('display_errors',1);
	error_reporting(E_ALL);

	dol_include_once('/kelio/class/kelio.class.php');

	$k=new KelioBridge($db);

	//$k->getTimeFromOuterSpace();
	$k->getTimeForLastDays(30);
	//$k->getClocking();
	//$k->getEmployees();
