<?
// Copyright 2006, Robert Pufky
// Written 06-22-2006: Configuration options for database

// constants
$fatal = true;

// Configure and connect to mysql db.
$dbuser = 'DB_USER';
$dbname = 'DB_NAME';
$dbserver = 'localhost';
$pass = 'ENTER_PASSWORD';

if(!mysql_pconnect($dbserver,$dbuser,$pass)) {
        die("Halted: database connection error.");
} else if(!mysql_select_db($dbname)) {
        die("Halted: database selection error.");
}

// function for logging errors.  requires $program and $die to be defined.
function mlog($user,$function,$fatal,$action) {
	global $program;
	global $die;

	$query = "insert into logs (did,stamp,program,function,action,fatal) values($user,NOW(),'$program','$function','$action','$fatal')";

	// log entry to the database (date, time, message), or die.
	mquery($query);
	
	// print message
	if( $fatal ) {
		print "\nError: $action\n\n";
		die($die);
	}
}

// querys, and wraps error reporting
function mquery($query) {
	$results = mysql_query($query);

	if( mysql_error() ) {
		echo "\n" . $query . "\n\n" . mysql_error() . "\n";
		die("Halting: Invalid mysql query.  Cannot insert into database.");
	}
	
	return $results;
}

// for prompting for input if necessary
function getinput() {
	# get input from command line, max 255, trim whitespace
	$input = fopen("php://stdin","r");
	$cmd = trim(fgets($input,255));
	fclose($input);

	return $cmd;
}

// for removing contents from a given filename
// removes any line matched by search
function remove($file,$search,$function) {
	//verify ability to write and open
	//if( !is_writable($file) ) { mlog(0,$function,$fatal,"$file is not set for writing.  Make sure proper permissions are set."); }
	if( !$finput = file($file) ) { mlog(0,$function,$fatal,"Cannot open $file for reading.  Make sure proper permissions are set."); }
	
	if( !$fpipe = fopen($file,'w') ) { mlog(0,$function,$fatal,"Cannot open $file for writing.  Make sure proper permissions are set."); }

	//remove lines from file
	foreach ($finput as $line) {
		if( strpos($line,$search) === false ) { fwrite($fpipe,"$line"); }
	}

	fclose($fpipe);
}
?>
