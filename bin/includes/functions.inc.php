<?
/* 
  Copyright 2006, Robert Pufky
  Written 06-22-2006

  Global functions for scripts
*/

// define a constant for FATAL
define ("FATAL",true);

// connect to mysql server
require("config.inc.php");
if(!mysql_pconnect($dbserver,$dbuser,$rootpass)) { die("Halted: database connection error."); } 
if(!mysql_select_db($dbname)) { die("Halted: database selection error."); }

// Function: mque
// Purpose:  wrap and execute a query, automatically producing FATAL error if nescessary
// Requires: string - the mysql query to use
function mque($query) {
	$results = mysql_query($query);
	if( mysql_error() ) { die("\nFatal Mysql Error.\n\nMysql Query: $query\n\nMysql Error: " . mysql_error() . "\n\n"); }
	return $results;
}

// Function: mlog
// Purpose:  write program action to log file, a fatal log will halt the program
// Requires: string - program, program.function, or program.action being preformed
//           boolean - true if fatal, false otherwise
//           description - a short, concise description of the action being taken
function mlog($function,$fatal,$action) {
	$query = "insert into logs (stamp,function,fatal,action) values(NOW(),'$function','$fatal','$action')";
	mque($query);
	if( $fatal ) { die("\n\nFatal Error: $function produced a fatal error: $action\n\n"); }
}

// Function: getinput
// Purpose:  prompt for, and return requested input
// Requires: string - the text for the prompt
function getinput($prompt) {
	echo "\n$prompt: ";
	return trim(fread(STDIN,255));
}

// Function: remove
// Purpose:  removes lines containing search, and a # of lines following that
// Requires: string - search line to be removed (use full line if you only want that line
//           string - the location of the file to modify
function remove($search,$filepath) {
	// verify file exists
	if( !file_exists($filepath) ) { mlog("function.remove",FATAL,"Could not open file: $filepath."); }
	// read the file into an array
	if( !$file = file($filepath) ) { mlog("function.remove",FATAL,"Could not read file: $filepath."); }
	// open the file for re-writing
	if( !$fpipe = fopen($filepath,'w') ) { mlog("function.remove",FATAL,"Could not write to file: $filepath."); }

	foreach ($fpipe as $line) {
		// if we didn't find the line
		if( strpos($line,$search) === false ) {fwrite($fpipe,$line); }
	}
	
	fclose($fpipe);
}

// Function: write_subdomain
// Purpose:  appends subdomain configuration information to the apache.conf file
// Requires: string - valid IP address of the site
//           string - login name of the user
//           string - domain name
//           string - subdomain name
function write_subdomain($ip,$login,$domain,$subdomain) {
exec("echo '
NameVirtualHost $ip:80
<VirtualHost $ip:80>
  ServerName $subdomain.$domain
  ServerAdmin webmaster@$subdomain.$domain
  DocumentRoot /home/$user/www/$subdomain
  ErrorLog /home/$user/logs/$subdomain.$domain-error.log
  CustomLog /home/$user/logs/$subdomain.$domain-access.log common
</VirtualHost>

# Note: by default, site uses generic SSL Cert.
# Custom Certs: remove apache.pem line and uncomment .crt,.key lines
# subsituting your files for the generic ones
NameVirtualHost $ip:443
<VirtualHost $ip:443>
  SSLEngine On
  SSLCertificateFile /etc/apache2/ssl/apache.pem
  #SSLCertificateFile /home/$login/etc/<your cert>.crt
  #SSLCertificateKeyFile /home/$login/etc/<your cert>.key
  SSLProtocol all
  SSLCipherSuite HIGH:MEDIUM
  ServerName $subdomain.$domain
  ServerAdmin webmaster@$subdomain.$domain
  DocumentRoot /home/$user/www/$subdomain
  ErrorLog /home/$user/logs/ssl-$subdomain.$domain-error.log
  CustomLog /home/$user/logs/ssl-$subdomain.$domain-access.log common
</VirtualHost>' >> /home/$user/etc/apache.conf");
}
?>
