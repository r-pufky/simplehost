<?
/* 
  Copyright 2006, Robert Pufky
  Written 06-22-2006

  Admin functions for scripts

  load after config.inc.php/functions.inc.php

  if updating the sudoers line, remember to do both!
*/

// Function: add
// Purpose:  add and verify a new domain on the system
// Requires: string - the domain to be added
function add($domain) {
	global $rootpass;

	mlog("hostmodify.add",!FATAL,"Request to add $domain.");
	
	// verify domain is not being used
	$results = mque("select * from domains where domain='$domain'");
	if( mysql_num_rows($results) != 0 ) { mlog("hostmodify.checkduplicatedomain",FATAL,"$domain already hosted!"); }
		
	// prompt and verify IP address
	$ip = getinput("Enter site IP address");
	if( ip2long($ip) == -1 ) { mlog("hostmodify.checkip",FATAL,"$ip is NOT valid!"); }
	
	// verify IP is not being used
	$results = mque("select * from domains where ip='$ip'");
	if( mysql_num_rows($results) != 0 ) { mlog("hostmodify.checkduplicateip",FATAL,"$ip is already in use!"); }

	// create bash name and mysql name
	$bash = str_replace(" ","",str_replace(".","-",$domain));
	$mysql = str_replace("-","_",$bash);
			 
	$email = mysql_real_escape_string(getinput("Enter contact e-mail"));
	$name = getinput("Enter contact name");

	// insert domain, pull domain information and insert port information
	mque("insert into domains (domain,ip,name,email,password,mysql,bash,gallery2) values('$domain','$ip','$name','$email','changeme','$mysql','$bash','N')");
	$results = mysql_fetch_array(mque("select * from domains where domain='" . $domain . "'"));
	mque("insert into ports (did,port) values('" . $results['id'] . "','80')");
	mque("insert into ports (did,port) values('" . $results['id'] . "','443')");
	mlog("hostmodify.insertdomain",!FATAL,"Inserted $domain table information.");
	echo("\nCreated domain account.");

	// the interface number for the ethernet card is the domains id
	write_interfaces($ip,$results['id']);
	
	// create the bash user, default directories, and base files
	exec("adduser --disabled-login --gecos '$domain' $bash");
	exec("addgroup $bash www-data");
	exec("mkdir /home/$bash/backup /home/$bash/www /home/$bash/www/$domain /home/$bash/logs /home/$bash/etc");
	write_apache2($ip,$bash,$domain);
	write_logrotate($bash);
	write_analog($bash);
	exec("touch /home/$bash/logs/$domain-error.log /home/$bash/logs/$domain-access.log");
	exec("touch /home/$bash/logs/ssl-$domain-error.log /home/$bash/logs/ssl-$domain-access.log");
	echo("\nCreated base directories and configuration files.");
	
	// secure directories and files
	exec("chmod -R o-rwx /home/$bash");
	exec("chmod o+rx /home/$bash");
	exec("chmod -R 750 /home/$bash/backup /home/$bash/www /home/$bash/etc");
	exec("chmod 660 /home/$bash/etc/apache.conf");
	exec("chmod 660 /home/$bash/etc/logrotate.conf");
	exec("chmod 600 /home/$bash/logs/*");
	exec("chown -R $bash:www-data /home/$bash/www /home/$bash/etc");
	exec("chown -R $bash:$bash /home/$bash/backup /home/$bash/logs");
	echo("\nSecured directories and configuration files.");

	// create mysql database and secure it
	exec("mysql -u root -p$rootpass -e 'create database $mysql'");
	exec("mysql -u root -p$rootpass -e \"grant all privileges on $mysql.* to '$mysql'@'localhost' identified by 'changeme'\"");
	echo("\nCreated database and secured.");

	// write sudoers execution permissions and apache port files out
	write_sudoers($bash);
	write_ports($results['id'],$ip);
	echo("\nCreated port configuration.");

	// enable the website
	echo("\nEnabling user and website... ");
	exec("/root/bin/passwd.exp $bash changeme > /dev/null");
	exec("ifup eth0:" . $results['id']);
	exec("a2ensite $domain");
	exec("apache2ctl graceful");
	echo "done!\n\n";
	mlog("hostmodify.add",!FATAL,"$domain added successfuly!");
	echo "\n\nLogin username:   $bash\nDB Username:      $mysql\nDB Name:          $mysql\nDefault password: changeme\n\n";
}

// Function: del
// Purpose:  delete a domain on the system
// Requires: string - the domain to be deleted
function del($domain) {
	global $rootpass;
	
	mlog("hostmodify.delete",!FATAL,"Request to delete $domain.");
	$results = mysql_fetch_array(mque("select * from domains where domain='$domain'"));
	
	// disable the interface / website
	exec("a2dissite $domain");
	exec("apache2ctl graceful");
	exec("ifdown eth0:" . $results['id']);
	exec("rm /etc/apache2/sites-available/$domain");
	exec("rm /etc/logrotate.d/$domain");
	exec("rm /etc/analog/$domain");
	echo("\nDisabled network interface and apache.");

	// delete the user account and home directory
	exec("deluser " . $results['bash']);
	exec("rm -rf /home/" . $results['bash']);
	echo("\nDeleted user account.");

	// delete the user database / user, and reload mysql
	exec("mysql -u root -p$rootpass -e 'drop database " . $results['mysql'] . "'");
	exec("mysql -u root -p$rootpass -e \"delete from mysql.user where Host='localhost' and User='" . $results['mysql'] . "'\"");
	exec("mysql -u root -p$rootpass -e \"flush privileges\"");
	echo("\nDeleted database.");

	// remove from sudoers file, apache port configuration
	remove($results['id']);
	echo("Removed configuration information.");

	// remove domain from database and reload apache
	mque("delete from ports where did='" . $results['id'] . "'");
	mque("delete from subdomains where did='" . $results['id'] . "'");
	mque("delete from domains where id='" . $results['id'] . "'");
	echo("\nRemoved domain from database.\nReloading apache...");
	exec("/etc/init.d/apache2 reload");
	echo("done!");
	
	mlog("hostmodify.delete",!FATAL,"Deleted $domain.");
	echo("\n\ndomain has been deleted.\n\nDouble check /etc/network/interfaces and remove " . $results['ip'] . " lines.\n\n");
}

// Function: write_apache2
// Purpose:  writes the apache2 configuration file to specified location and links it
// Requires: string - valid IP address of the site
//           string - login name of the user
//           string - domain name
function write_apache2($ip,$login,$domain) {
	exec("echo '<VirtualHost $ip:80>
  ServerName $domain
  ServerAdmin webmaster@$domain
  DocumentRoot /home/$login/www/$domain
  ErrorLog /home/$login/logs/$domain-error.log
  CustomLog /home/$login/logs/$domain-access.log common
  Options none
</VirtualHost>

# Note: by default, site uses generic SSL Cert.
# Custom Certs: remove apache.pem line and uncomment .crt,.key lines
# subsituting your files for the generic ones
<VirtualHost $ip:443>
  SSLEngine On
  SSLCertificateFile /etc/apache2/ssl/apache.pem
  #SSLCertificateFile /home/$login/etc/<your cert>.crt
  #SSLCertificateKeyFile /home/$login/etc/<your cert>.key
  SSLProtocol all
  SSLCipherSuite HIGH:MEDIUM
  ServerName $domain
  ServerAdmin webmaster@$domain
  DocumentRoot /home/$login/www/$domain
  ErrorLog /home/$login/logs/ssl-$domain-error.log
  CustomLog /home/$login/logs/ssl-$domain-access.log common
  Options none
</VirtualHost>' > /home/$login/etc/apache.conf");

	exec("ln -s /home/$bash/etc/apache.conf /etc/apache2/sites-available/$domain");
}

// Function: write_sudoers
// Purpose:  writes the sudoers configuration to the sudoers file
// Requires: string - login name of the user
function write_sudoers($login) {
	exec("echo '$login    ALL = NOPASSWD: /root/bin/backup $login *,/root/bin/import $login *,/root/bin/reload $login,/root/bin/subdomain $login,/root/bin/pass $login' >> /etc/sudoers");
}

// Function: write_ports
// Purpose:  write the apache2 port configuration to the ports.conf file
// Requires: string - the database id of the domain
//           string - valid IP of the domain
function write_ports($id,$ip) {
	$results = mque("select * from ports where did='" . $id . "'");

	while( $port = mysql_fetch_array($results) ) {
		exec("echo -e -n '\nListen $ip:" . $port['port'] . "' >> /etc/apache2/ports.conf");
	}
}

// Function: write_interfaces
// Purpose:  write the given interface to the interfaces file
// Requires: string - valid IP address to add
//           integer - the number of the interface to add
function write_interfaces($ip,$num) {
	exec("echo -e -n '\n\niface eth0:$num inet static\naddress $ip\nnetmask 255.255.255.0' >> /etc/network/interfaces");
}

// Function: write_gallery
// Purpose:  rewrites the gallery2 configuration file with user credentials
// Requires: string - the domain name
//           string - the bash user name
//           string - the mysql user name
//           string - the mysql password
Function write_gallery($domain,$bash,$mysql,$pass) {
	// read configuration file for gallery2 and make backup
	$filepath = "/home/$bash/www/$domain/gallery2/config.php";
	if( !file_exists($filepath) ) { mlog("gallerypostsetup.writeconfig",FATAL,"Gallery configuration file does not exist!"); }
	$config = file($filepath);
	exec("cp $filepath /home/$bash/gallery.config.backup");

	// verify we can write to the file
	if( !is_writeable($filepath) ) { mlog("gallerypostsetup.writeconfig",FATAL,"Gallery configuration file is not writable!"); }
	if( !$fpipe = fopen($filepath,'w') ) { mlog("gallerypostsetup.writeconfig",FATAL,"Gallery configuration file cannot be written to!"); }

	// rewrite the file, but with users database information
	foreach ( $config as $line ) {
		// if it is a user line, then write it with the user information
		if( strpos($line, "\$storeConfig['username'] = '") ) {
			if( fwrite($fpipe, "\$storeConfig['username'] = '$mysql';\n") === false ) {
				mlog("gallerypostsetup.writeconfig",FATAL,"Could not write password to configuration file!");
			}
		// if it is a password line then write it with users information
		} else if( strpos($line, "\$storeConfig['password'] = '") ) {
			if( fwrite($fpipe, "\$storeConfig['password'] = '$pass';\n") === false ) {
				mlog("gallerypostsetup.writeconfig",FATAL,"Could not write password to configuration file!");
			}
		} else {
			// we don't have the password line, just write it
			if( fwrite($fpipe, $line) === false ) { mlog("gallerypostsetup.writeconfig",FATAL,"Could not write to configuration file!"); }
		}
	}
	mlog("gallerypostsetup.writeconfig",!FATAL,"Gallery configuration file written successfully!");
}

// Function: remove
// Purpose:  removes sites configuration information from /etc/sudoers and /etc/apache2/ports.conf
// Rquires:  string - the id of the domain
Function remove($id) {
	$sudopath = "/etc/sudoers";
	$portpath = "/etc/apache2/ports.conf";
	
	// grab domain and port information
	if( !$results = mysql_fetch_array(mque("select * from domains where id='" . $id . "'")) ) { mlog("remove.checkdomain",FATAL,"Domain not found!"); }
	if( !$ports = mque("select * from ports where did='" . $id . "'") ) { mlog("remove.checkports",FATAL,"No ports found!"); }

	// process the sudoers file
	$bash = $results['bash'];
	$file = file($sudopath);
	
	// attempt to open sudoers file for writing
	if( !$fpipe = fopen($sudopath,'w') ) { mlog("remove.rewritesudoers",FATAL,"Cannot open sudoers file!"); }
	
	// go through the sudoers file
	foreach ( $file as $line ) {
		// if the sudoers line is not found, write to the files
		if( strpos($line, "$bash    ALL = NOPASSWD: /root/bin/backup $bash *,/root/bin/import $bash *,/root/bin/reload $bash,/root/bin/subdomain $bash,/root/bin/pass $login") === false ) {
			// write the original line
			if( fwrite($fpipe, $line) === false ) { mlog("remove.rewritesudoers",FATAL,"Could not write to sudoers file!"); }
		}
	}

	fclose($fpipe);

	// process the ports.conf file
	while( $port = mysql_fetch_array($ports) ) {
		// read ports files and prepare to write
		$file = file($portpath);
		if( !$fpipe = fopen($portpath,'w') ) { mlog("remove.rewriteports",FATAL,"Cannot open ports file!"); }

		// go through the ports file
		foreach ( $file as $line ) {
			// if the port is not found
			if( strpos($line, "Listen " . $results['ip'] . ":" . $port['port']) === false ) {
				// write the line
				if( fwrite($fpipe, $line) === false ) { mlog("remove.rewriteports",FATAL,"Could not write to ports file!"); }
			}
		}

		fclose($fpipe);
	}
	mlog("remove.main",!FATAL,"Successfully removed sudo user and ports.");
}

// Function: write_htaccess
// Purpose:  writes htaccess file for gallyer to prevent people browsing
// Requires: string - the domain name
//           string - the bash user name
Function write_htaccess($domain,$bash) {
	exec("echo '<Files ~ \"\\.(inc|class)$\">
  Deny from all
</Files>
<Files ~ \"config.php\">
  Deny from all
</Files>' > /home/$bash/www/$domain/gallery2/.htaccess");
}

// Function: write_logrotate
// Purpose:  writes a default logrotate configuration file for user and links it
// Requires: string - the bash user name
Function write_logrotate($bash) {
	exec("echo '#You REALLY should have NO NEED to modify this file.
# If you mess up the configuration, your log rotations will STOP WORKING!
# Advanced users only please!  Contact Gabe or Bob with questions!
# This script is automatically run every day at 11:30 PM.
#
# compress, missing log files will not give error, will use same logfile
# rotate every 2 megs, keep last 6 rotates
/home/$bash/logs/*.log {
	size 2M
	rotate 6
	compress
	copytruncate
	missingok
}' > /home/$bash/etc/logrotate.conf");

	exec("ln -s /home/$bash/etc/logrotate.conf /etc/logrotate.d/$domain");
}

// Function: write_analog
// Purpose:  writes a default analog web analyzer configuration file for user, links it, and 
//           updates the cronjob for analog
// Requires: string - the domain name
//           string - the bash name
Function write_analog($domain,$bash) {
	exec("echo '#You REALLY shoud have NO NEED to modify this file.
# If you mess up the configuration, your website analysis will STOP WORKING!
# Advanced user only please!  Contact Gabe or Bob with questions!
# This script is automatically run everyday at 11:30 PM
#
# Goto http://www.rix-web.com/analyzer/ for an automatic configuration file maker!
#
LOGFILE /home/$bash/logs/*.log
OUTPUT HTML
OUTFILE /home/$bash/www/admin/analog/%y%M%D-Statistics.html
DNS LOOKUP
general ON
Monthly ON
Weekly ON
Dailysum ON
DAILYREP OFF
HOURLYSUM ON
DOMAIN ON
ORGANISATION ON
DIRECTORY ON
FILETYPE ON
SIZE ON
REQUEST ON
REFERRER ON
FAILURE ON
SEARCHQUERY ON
SEARCHWORD ON
BROWSERSUM ON
OSREP ON
STATUS ON
DOMSORTBY BYTES
DOMFLOOR 0b
ORGSORTBY REQUESTS
ORGFLOOR 0r
DIRSORTBY BYTES
DIRFLOOR 0b
REQSORTBY REQUESTS
REQFLOOR 0r
REQINCLUDE *
REFSORTBY PAGES
REFFLOOR 0p
FROM
TO
FILEINCLUDE
FILEEXCLUDE
HOSTNAME $domain
HOSTURL http://www.$domain
#<----------STATIC VARIABLES --------------------->

IMAGEDIR images/
DNSGOODHOURS 100000
DNSBADHOURS 336
DNSTIMEOUT 10
DNSLOCKFILE dnslock
# A list of search engines
SEARCHENGINE http://*altavista.*/* q
SEARCHENGINE http://*yahoo.*/* p
SEARCHENGINE http://*google.*/* q
SEARCHENGINE http://*lycos.*/* query
SEARCHENGINE http://*aol.*/* query
SEARCHENGINE http://*excite.*/* search
SEARCHENGINE http://*go2net.*/* general
SEARCHENGINE http://*metacrawler.*/* general
SEARCHENGINE http://*msn.*/* MT
SEARCHENGINE http://*hotbot.com/* MT
SEARCHENGINE http://*netscape.*/* search
SEARCHENGINE http://*looksmart.*/* key
SEARCHENGINE http://*infoseek.*/* qt
SEARCHENGINE http://*webcrawler.*/* search,searchText
SEARCHENGINE http://*goto.*/* Keywords
SEARCHENGINE http://*snap.*/* keyword
SEARCHENGINE http://*dogpile.*/* q
SEARCHENGINE http://*askjeeves.*/* ask
SEARCHENGINE http://*ask.*/* ask
SEARCHENGINE http://*aj.*/* ask
SEARCHENGINE http://*directhit.*/* qry
SEARCHENGINE http://*alltheweb.*/* query
SEARCHENGINE http://*northernlight.*/* qr
SEARCHENGINE http://*nlsearch.*/* qr
SEARCHENGINE http://*dmoz.*/* search
SEARCHENGINE http://*newhoo.*/* search
SEARCHENGINE http://*netfind.*/* query,search,s
SEARCHENGINE http://*/netfind* query
SEARCHENGINE http://*/pursuit query
BROWOUTPUTALIAS Mozilla Netscape
BROWOUTPUTALIAS "Mozilla (compatible)" "Netscape (compatible)"
BROWOUTPUTALIAS IWENG AOL
TYPEOUTPUTALIAS .html ".html [Hypertext Markup Language]"
TYPEOUTPUTALIAS .htm ".htm [Hypertext Markup Language]"
TYPEOUTPUTALIAS .shtml ".shtml [Server-parsed HTML]"
TYPEOUTPUTALIAS .ps ".ps [PostScript]"
TYPEOUTPUTALIAS .gz ".gz [Gzip compressed files]"
TYPEOUTPUTALIAS .tar.gz ".tar.gz [Compressed archives]"
TYPEOUTPUTALIAS .jpg ".jpg [JPEG graphics]"
TYPEOUTPUTALIAS .jpeg ".jpeg [JPEG graphics]"
TYPEOUTPUTALIAS .gif ".gif [GIF graphics]"
TYPEOUTPUTALIAS .png ".png [PNG graphics]"
TYPEOUTPUTALIAS .txt ".txt [Plain text]"
TYPEOUTPUTALIAS .cgi ".cgi [CGI scripts]"
TYPEOUTPUTALIAS .pl ".pl [Perl scripts]"
TYPEOUTPUTALIAS .css ".css [Cascading Style Sheets]"
TYPEOUTPUTALIAS .class ".class [Java class files]"
TYPEOUTPUTALIAS .pdf ".pdf [Adobe Portable Document Format]"
TYPEOUTPUTALIAS .zip ".zip [Zip archives]"
TYPEOUTPUTALIAS .hqx ".hqx [Macintosh archives]"
TYPEOUTPUTALIAS .exe ".exe [Executables]"
TYPEOUTPUTALIAS .wav ".wav [WAV sound files]"
TYPEOUTPUTALIAS .avi ".avi [AVI movies]"
TYPEOUTPUTALIAS .arc ".arc [Compressed archives]"
TYPEOUTPUTALIAS .mid ".mid [MIDI sound files]"
TYPEOUTPUTALIAS .mp3 ".mp3 [MP3 sound files]"
TYPEOUTPUTALIAS .doc ".doc [Microsoft Word document]"
TYPEOUTPUTALIAS .rtf ".rtf [Rich Text Format]"
TYPEOUTPUTALIAS .mov ".mov [Quick Time movie]"
TYPEOUTPUTALIAS .mpg ".mpg [MPEG movie]"
TYPEOUTPUTALIAS .mpeg ".mpeg [MPEG movie]"
TYPEOUTPUTALIAS .asp ".asp [Active Server Pages]"
TYPEOUTPUTALIAS .jsp ".jsp [Java Server Pages]"
TYPEOUTPUTALIAS .cfm ".cfm [Cold Fusion]"
TYPEOUTPUTALIAS .php ".php [PHP]"
TYPEOUTPUTALIAS .js ".js [JavaScript code]"
TYPEOUTPUTALIAS .ico ".ico [Icon]"
PAGEINCLUDE *.shtml
PAGEINCLUDE *.asp
PAGEINCLUDE *.jsp
PAGEINCLUDE *.cfm
PAGEINCLUDE *.pl
PAGEINCLUDE *.php
PAGEINCLUDE *.pdf
PAGEINCLUDE *.doc
ROBOTINCLUDE REGEXPI:robot
ROBOTINCLUDE REGEXPI:spider
ROBOTINCLUDE REGEXPI:crawler
ROBOTINCLUDE Googlebot*
ROBOTINCLUDE Infoseek*
ROBOTINCLUDE Scooter*
ROBOTINCLUDE Slurp*
ROBOTINCLUDE Ultraseek*i' > /home/$bash/etc/analog.conf");

	// grab all domains and re-write analog cronjob
	$results = mque("select * from domains");
	$cron = "#!/bin/sh\n\n";

	while( $domain = mysql_fetch_array($result) ) {
		$cron += "/usr/bin/analog -G +g/home/" . $domain['bash'] . "/etc/analog.conf\n";
	}

	// write file and set default permissions
	exec("echo '$cron' > /etc/cron.daily/analog");
	exec("chmod 755 /etc/cron.daily/analog");
}
?>
