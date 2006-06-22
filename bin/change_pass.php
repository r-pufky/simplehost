#!/usr/bin/php4
<?
// Robert Pufky
// 2006-05-08 - User account creation script
//
// Note: This script MUST be run as the root user.
//       Make sure permissions are set to execute, with read/write disabled.
//	Must be launched with the current username sepcified (see wrapper)
$fatal = true;  //constant for fatal error

function error($fatal,$message) {
	// log entry to the database (date, time, message)
	
	// print message
	if( $fatal ) {
		die("\nError: $message\n\n");
	} else {
		print "\nWarning: $message\n";
	}
}

// figure out what user we are
$username = $argv[1];
// convert to DB compliant username
$DBName = str_replace("-","_",$username);
// figure out where the password file is
$passpath = "/home/$username/passwd";

// if we are trying to change the root password, die.
if( $username == "root" ) {
	error($fatal,"Cannot change the root password\n\nPassword NOT Changed.");
}

// If the password file does not exist, die.
if( !file_exists("$passpath") ) {
	error($fatal,"Passwd file does not exist!\n\nPassword file should be located at: $passpath\n  'touch $passpath'\n\nPassword NOT Changed.");
}

// If the password file is more than readable by owner, insecure, die.
if( @substr(sprintf('%o', fileperms("$passpath")), -4) != "0400" ) {
	error($fatal,"Incorrect permissions on passwd file!\n\n  $passpath should be readable only by you!\n   'chmod 0400 $passpath'\n\nPassword NOT Changed.");
}

// read the password file and set the pass (removing trailing whitespace and newline characters), if we can't, die.
if( !$userpass = rtrim(file_get_contents("$passpath")) ) {
	error($fatal,"Cannot read passwd file, or passwd file empty.\n\nPassword NOT Changed.");
}

// grab the root password
require_once("config.inc.php");
// change the user's password
exec("/root/bin/passwd-noninteractive.exp $username $userpass > /dev/null");
echo "Updated shell password.\n";
// change the mysql user's password
exec("mysql -u root -p$pass -e \"set password for '$DBName'@'localhost' = PASSWORD('$userpass')\"");
echo "Updated database password.\n";
echo "done!\n\n";
?>
