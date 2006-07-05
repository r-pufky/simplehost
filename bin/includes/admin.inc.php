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
		
	// show used IP's
	echo "\nIP's in use are (use the next IP not listed):\n";
	$showlist = mque("select * from domains order by id");
	while( $showrow = mysql_fetch_array($showlist) ) {
		echo "$showrow[ip]\n";
	}
	
	// prompt and verify IP address
	$ip = getinput("Enter site IP address");
	if( ip2long($ip) == -1 ) { mlog("hostmodify.checkip",FATAL,"$ip is NOT valid!"); }
	
	// verify IP is not being used
	$results = mque("select * from domains where ip='$ip'");
	if( mysql_num_rows($results) != 0 ) { mlog("hostmodify.checkduplicateip",FATAL,"$ip is already in use!"); }

	// create bash name and mysql name
	$bash = str_replace(" ","",str_replace(".","-",$domain));
	$mysql = str_replace("-","_",$bash);

	// the mysql name can only be 16 character long, take the first 16
	if( strlen($mysql) > 16 ) { $mysql = substr($mysql, 0, 16); }
			 
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
	write_interfaces($ip,$results['id'],$domain);
	
	// create the bash user, default directories, and base files
	exec("adduser --disabled-login --gecos '$domain' $bash");
	exec("addgroup $bash www-data");
	exec("mkdir -p /home/$bash/www/www/admin/analog/images /home/$bash/www/www/admin/analog/reports");
	exec("ln -s /usr/share/phpmyadmin /home/$bash/www/www/admin");
	exec("mkdir /home/$bash/backup /home/$bash/logs /home/$bash/etc");
	write_admin($results['id']);
	write_apache2($ip,$bash,$domain);
	write_logrotate($bash,$domain);
	write_analog($bash,$domain);
	exec("touch /home/$bash/logs/$domain-error.log /home/$bash/logs/$domain-access.log");
	exec("touch /home/$bash/logs/ssl-$domain-error.log /home/$bash/logs/ssl-$domain-access.log");
	echo("\nCreated base directories and configuration files.");
	
	// secure directories and files
	exec("chmod -R o-rwx /home/$bash");
	exec("chmod o+rx /home/$bash");
	exec("chmod -R 750 /home/$bash/backup /home/$bash/www /home/$bash/etc");
	exec("chmod 660 /home/$bash/etc/*");
	exec("chmod 660 /home/$bash/www/www/admin/.htaccess");
	exec("chmod 600 /home/$bash/logs/*");
	exec("chown -R $bash:www-data /home/$bash/www /home/$bash/etc");
	exec("chown -R $bash:$bash /home/$bash/backup /home/$bash/logs");
	echo("\nSecured directories and configuration files.");

	// create mysql database and secure it
	exec("mysql -u root -p$rootpass -e 'create database $mysql'");
	exec("mysql -u root -p$rootpass -e \"grant all privileges on $mysql.* to '$mysql'@'localhost' identified by 'changeme'\"");
	echo("\nCreated database and secured.");

	// write sudoers execution permissions and apache port files out
	write_configs();
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

	// remove domain from database and reload apache
	mque("delete from ports where did='" . $results['id'] . "'");
	mque("delete from subdomains where did='" . $results['id'] . "'");
	mque("delete from domains where id='" . $results['id'] . "'");
	mque("delete from mysql.db where Host='localhost' and Db='" . $results['mysql'] . "'");
	echo("\nRemoved domain from database.");
	
	// reload configuration files for sudoers and ports.conf
	write_configs();
	echo("\nRemoved configuration information.");
	
	echo("\nReloading apache...");
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
  DocumentRoot /home/$login/www/www
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
  DocumentRoot /home/$login/www/www
  ErrorLog /home/$login/logs/ssl-$domain-error.log
  CustomLog /home/$login/logs/ssl-$domain-access.log common
  Options none
</VirtualHost>' > /home/$login/etc/apache.conf");

	exec("ln -s /home/$login/etc/apache.conf /etc/apache2/sites-available/$domain");
}

// Function: write_configs
// Purpose:  writes an entirely new /etc/sudoers and /etc/apache2/ports.conf file from database
// Requires: none
function write_configs() {
	global $serverip;
	global $serverport;
	global $serverport2;

	// grab a list of domains
	$domains = mque("select * from domains");

	// set config files to their default state
	exec("echo '' > /etc/sudoers;echo -e 'Listen $serverip:$serverport\nListen $serverip:$serverport2' > /etc/apache2/ports.conf");

	// go through each domain
	while( $domain = mysql_fetch_array($domains) ) {
		$bash = $domain['bash'];
		// write sudoers information
		exec("echo '$bash    ALL = NOPASSWD: /root/bin/backup $bash *,/root/bin/import $bash *,/root/bin/reload $bash,/root/bin/subdomain $bash,/root/bin/pass $bash' >> /etc/sudoers");

		// write apache information
		$results = mque("select * from ports where did='" . $domain['id'] . "'");
		while( $port = mysql_fetch_array($results) ) {
			exec("echo -e -n '\nListen " . $domain['ip'] . ":" . $port['port'] . "' >> /etc/apache2/ports.conf");
		}
	}
}

// Function: write_interfaces
// Purpose:  write the given interface to the interfaces file
// Requires: string - valid IP address to add
//           integer - the number of the interface to add
//           string - the domain name
function write_interfaces($ip,$num,$domain) {
	exec("echo -e -n '\n\n# Interface for $domain\niface eth0:$num inet static\naddress $ip\nnetmask 255.255.255.0' >> /etc/network/interfaces");
}

// Function: write_gallery
// Purpose:  rewrites the gallery2 configuration file with user credentials
// Requires: string - the domain name
//           string - the bash user name
//           string - the mysql user name
//           string - the mysql password
Function write_gallery($domain,$bash,$mysql,$pass) {
	global $serverip;

	// generate a random password for the core password
	$gallerycore = "";
	$charset = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdfghjklmnpqrstvwxyz"; 
	
	// generate a 10 character password
	$i = 0; 
	while ($i < 10) { 
		// pick a random character from the possible ones
		$char = substr($charset, mt_rand(0, strlen($charset)-1), 1);
	        if (!strstr($gallerycore, $char)) { 
			$gallerycore .= $char;
			$i++;
		}
	}

	// read configuration file for gallery2 and make backup
	$filepath = "/home/$bash/www/www/gallery2/config.php";
	if( !file_exists($filepath) ) { mlog("gallerypostsetup.writeconfig",FATAL,"Gallery configuration file does not exist!"); }
	$config = file($filepath);
	exec("cp $filepath /home/$bash/gallery.config.backup");

	// verify we can write to the file
	if( !is_writeable($filepath) ) { mlog("gallerypostsetup.writeconfig",FATAL,"Gallery configuration file is not writable!"); }
	if( !$fpipe = fopen($filepath,'w') ) { mlog("gallerypostsetup.writeconfig",FATAL,"Gallery configuration file cannot be written to!"); }

	// rewrite the file, but with users database information
	foreach ( $config as $line ) {
		// if it is a user line, then write it with the user information
		if( strpos($line, "\$storeConfig['username'] = '") !== false ) {
			if( fwrite($fpipe, "\$storeConfig['username'] = '$mysql';\n") === false ) {
				mlog("gallerypostsetup.writeconfig",FATAL,"Could not write username to configuration file!");
			}
		// if it is a password line then write it with users information
		} else if( strpos($line, "\$storeConfig['password'] = '") !== false ) {
			if( fwrite($fpipe, "\$storeConfig['password'] = '$pass';\n") === false ) {
				mlog("gallerypostsetup.writeconfig",FATAL,"Could not write password to configuration file!");
			}
		// randomize the core password for gallery (used if user forgets their password)
		} else if( strpos($line, "\$gallery->setConfig('setup.password',") !== false ) {
			if( fwrite($fpipe, "\$gallery->setConfig('setup.password','$gallerycore');\n") === false ) {
				mlog("gallerypostsetup.writeconfig",FATAL,"Could not write core password to configuration file!");
			}
		// point the installation to the base gallery2 site
		} else if( strpos($line,"\$gallery->setConfig('galleryBaseUrl'") !== false ) {
			if( fwrite($fpipe, "\$gallery->setConfig('galleryBaseUrl', 'http://$serverip/gallery2/');\n") === false ) {
				mlog("gallerypostsetup.writeconfig",FATAL,"Could not write core gallery2 server to configuration file!");
			}
		} else {
			// we don't have the password line, just write it
			if( fwrite($fpipe, $line) === false ) { mlog("gallerypostsetup.writeconfig",FATAL,"Could not write to configuration file!"); }
		}
	}

	exec("rm -f /home/$bash/gallery.config.backup");
	mlog("gallerypostsetup.writeconfig",!FATAL,"Gallery configuration file written successfully!");
}

// Function: remove
// Purpose:  removes sites configuration information from /etc/sudoers and /etc/apache2/ports.conf
// Requires:  string - the id of the domain
Function remove($id) {
	// grab domain and port information
	if( !$results = mysql_fetch_array(mque("select * from domains where id='" . $id . "'")) ) { mlog("remove.checkdomain",FATAL,"Domain not found!"); }
	if( !$ports = mque("select * from ports where did='" . $id . "'") ) { mlog("remove.checkports",FATAL,"No ports found!"); }

	// process the sudoers file
	$bash = $results['bash'];

	rewrite_file("/etc/sudoers","$bash    ALL = NOPASSWD: /root/bin/backup $bash *,/root/bin/import $bash *,/root/bin/reload $bash,/root/bin/subdomain $bash,/root/bin/pass $bash","remove.rewritesudoers");
	
	// process the ports.conf file
	while( $port = mysql_fetch_array($ports) ) {
		rewrite_file("/etc/apache2/ports.conf","Listen " . $results['ip'] . ":" . $port['port'],"remove.rewriteports");
	}
	
	mlog("remove.main",!FATAL,"Successfully removed sudo user and ports.");
}

// Function: rewrite_file
// Purpose:  rewrites given file, minus specified line
// Requires: string - file to open
//           string - exact line to remove
//           string - reporting function if an error occurs
Function rewrite_file($path,$search,$error) {
	// attempt to open sudoers file for writing
	if( !$fpipe = fopen($path,'w') ) { mlog($error,FATAL,"Cannot open $path file!"); }
	
	// go through the ports file
	foreach ( $file as $line ) {
		// if the port is not found
		if( strpos($line,$search) === false ) {
			// write the line
			if( fwrite($fpipe, $line) === false ) { mlog($error,FATAL,"Could not write to $path file!"); }
		}
	}

	fclose($fpipe);
}

// Function: write_htgallery
// Purpose:  writes htaccess file for gallery to prevent people browsing
// Requires: string - the domain name
//           string - the bash user name
Function write_htgallery($bash,$domain) {
	exec("echo '<Files ~ \"\\.(inc|class)$\">
  Deny from all
</Files>
<Files ~ \"config.php\">
  Deny from all
</Files>' > /home/$bash/www/www/gallery2/.htaccess");
}

// Function: write_admin
// Purpose:  sets up and writes administration section of domains website
// Requires: integer - the database id of the domain to setup
Function write_admin($id) {
	// grab domain information
	if( !$results = mysql_fetch_array(mque("select * from domains where id='$id'")) ) { mlog("add.write_admin",FATAL,"Could not load domain with id $id"); }
	// setup htpasswd file with domain account (forcing md5 hash)
	exec("/usr/bin/htpasswd2 -bcm /home/" . $results['bash'] . "/etc/htpasswd " . $results['bash'] . " " . $results['password'] . " 2>&1 > /dev/null");
	exec("chmod 640 /home/" . $results['bash'] . "/etc/htpasswd");
	// write .htaccess file for administrative area
	exec("echo '# Administrative htaccess file - DO NOT CHANGE or your admin area could become insecure
SSLRequireSSL
AuthType Basic
AuthName " . $results['domain'] . "
AuthUserFile /home/" . $results['bash'] . "/etc/htpasswd
Require valid-user
Options indexes FollowSymLinks' > /home/" . $results['bash'] . "/www/www/admin/.htaccess");

	// write simple administration links for idiots
	exec("echo '<HTML>
<body>
<a href='analog/reports/'>Analog log files</a><br><br>
<a href='phpmyadmin/index.php'>phpmyadmin</a>
</body>
</HTML>' > /home/" . $results['bash'] . "/www/www/admin/index.html");
}

// Function: write_logrotate
// Purpose:  writes a default logrotate configuration file for user and links it
// Requires: string - the bash user name
//           string - the domain name
Function write_logrotate($bash,$domain) {
	// must do it the slow way as the logrotate command is interperated as a shell command
	if( !$fpipe = fopen("/home/$bash/etc/logrotate.conf",'w') ) { mlog("add.logrotate",FATAL,"Cannot write to logrotate file!"); }
	
	fwrite($fpipe, "# You REALLY should have NO NEED to modify this file.
# If you mess up the configuration, your log rotations will STOP WORKING!
# Advanced users only please!  Contact Gabe or Bob with questions!
# This script is automatically run every day at 11:30 PM.
#
# By default, weekly log rotations, with a year's worth of logs stored.
/home/$bash/logs/*.log {
  weekly
  missingok
  rotate 52
  compress
  copytruncate
  notifempty
}");
	fclose($fpipe);
	exec("ln -s /home/$bash/etc/logrotate.conf /etc/logrotate.d/$domain");
}

// Function: write_analog
// Purpose:  writes a default analog web analyzer configuration file for user, links it, and 
//           updates the cronjob for analog
// Requires: string - the bash user name
//           string - the domain name
Function write_analog($bash,$domain) {
	exec("echo '# You REALLY shoud have NO NEED to modify this file.
# If you mess up the configuration, your website analysis will STOP WORKING!
# Advanced user only please!  Contact Gabe or Bob with questions!
# This script is automatically run everyday at 11:30 PM
#
# Goto http://www.rix-web.com/analyzer/ for an automatic configuration file maker!
#
LOGFILE /home/$bash/logs/*access.log
OUTPUT HTML
OUTFILE /home/$bash/www/www/admin/analog/index.html
HOSTNAME \"$domain\"
HOSTURL http://www.$domain
IMAGEDIR ../../images/
DNS LOOKUP
REQINCLUDE pages
REQLINKINCLUDE pages
REFLINKINCLUDE *
REDIRREFLINKINCLUDE *
FAILREFLINKINCLUDE *
SUBBROW */*
SUBTYPE *.gz,*.Z

PAGEINCLUDE *.php

# More SEARCHENGINE commands can be found at
#   http://www.analog.cx/helpers/#conffiles
SEARCHENGINE http://*google.*/* q,as_q,as_epq,as_oq
SEARCHENGINE http://*altavista.*/* q
SEARCHENGINE http://*yahoo.*/* p
SEARCHENGINE http://*lycos.*/* query,wfq
SEARCHENGINE http://*aol.*/* query
SEARCHENGINE http://*excite.*/* search
SEARCHENGINE http://*go2net.*/* general
SEARCHENGINE http://*metacrawler.*/* general
SEARCHENGINE http://*msn.*/* q,MT
SEARCHENGINE http://*netscape.*/* search
SEARCHENGINE http://*looksmart.*/* key
SEARCHENGINE http://*webcrawler.*/* qkw,search,searchText
SEARCHENGINE http://*overture.*/* Keywords
SEARCHENGINE http://*teoma.*/* q
SEARCHENGINE http://*infospace.*/* qkw
SEARCHENGINE http://*alltheweb.*/* q
SEARCHENGINE http://*dogpile.*/* q
SEARCHENGINE http://*ask.*/* ask
SEARCHENGINE http://*alltheweb.*/* query
SEARCHENGINE http://*northernlight.*/* qr
SEARCHENGINE http://*nlsearch.*/* qr
SEARCHENGINE http://*dmoz.*/* search
SEARCHENGINE http://*/netfind* query
SEARCHENGINE http://*/pursuit query
ROBOTINCLUDE REGEXPI:robot
ROBOTINCLUDE REGEXPI:spider
ROBOTINCLUDE REGEXPI:crawler
ROBOTINCLUDE Googlebot*
ROBOTINCLUDE Infoseek*
ROBOTINCLUDE Scooter*
ROBOTINCLUDE *Slurp*
ROBOTINCLUDE *Validator*
ROBOTINCLUDE Ultraseek*
TYPEALIAS .html    \".html [Hypertext Markup Language]\"
TYPEALIAS .htm     \".htm  [Hypertext Markup Language]\"
TYPEALIAS .shtml   \".shtml [Server-parsed HTML]\"
TYPEALIAS .ps      \".ps   [PostScript]\"
TYPEALIAS .gz      \".gz   [Gzip compressed files]\"
TYPEALIAS .tar.gz  \".tar.gz [Compressed archives]\"
TYPEALIAS .jpg     \".jpg  [JPEG graphics]\"
TYPEALIAS .jpeg    \".jpeg [JPEG graphics]\"
TYPEALIAS .gif     \".gif  [GIF graphics]\"
TYPEALIAS .png     \".png  [PNG graphics]\"
TYPEALIAS .txt     \".txt  [Plain text]\"
TYPEALIAS .cgi     \".cgi  [CGI scripts]\"
TYPEALIAS .pl      \".pl   [Perl scripts]\"
TYPEALIAS .css     \".css  [Cascading Style Sheets]\"
TYPEALIAS .class   \".class [Java class files]\"
TYPEALIAS .pdf     \".pdf  [Adobe Portable Document Format]\"
TYPEALIAS .zip     \".zip  [Zip archives]\"
TYPEALIAS .hqx     \".hqx  [Macintosh BinHex files]\"
TYPEALIAS .exe     \".exe  [Executables]\"
TYPEALIAS .wav     \".wav  [WAV sound files]\"
TYPEALIAS .avi     \".avi  [AVI movies]\"
TYPEALIAS .arc     \".arc  [Compressed archives]\"
TYPEALIAS .mid     \".mid  [MIDI sound files]\"
TYPEALIAS .mp3     \".mp3  [MP3 sound files]\"
TYPEALIAS .ogg     \".ogg  [OGG sound files]\"
TYPEALIAS .doc     \".doc  [Microsoft Word document]\"
TYPEALIAS .rtf     \".rtf  [Rich Text Format]\"
TYPEALIAS .mov     \".mov  [Quick Time movie]\"
TYPEALIAS .mpg     \".mpg  [MPEG movie]\"
TYPEALIAS .mpeg    \".mpeg [MPEG movie]\"
TYPEALIAS .asp     \".asp  [Active Server Pages]\"
TYPEALIAS .jsp     \".jsp  [Java Server Pages]\"
TYPEALIAS .cfm     \".cfm  [Cold Fusion]\"
TYPEALIAS .php     \".php  [PHP]\"
TYPEALIAS .js      \".js   [JavaScript code]\"' > /home/$bash/etc/analog.conf");
	exec("chmod 640 /home/$bash/etc/analog.conf");

	// copy over image files to analog directory
	exec("cp /usr/share/analog/images/* /home/$bash/www/www/admin/analog/images/");

	// grab all domains and re-write analog cronjob
	if( !$results = mque("select * from domains") ) { mlog("add.writeanalog",!FATAL,"Could not find any domains to write!"); }
	$cron = "#!/bin/sh\n\n";

	while( $domain = mysql_fetch_array($results) ) {
		$cron = $cron . "/usr/bin/analog -G +g/home/" . $domain['bash'] . "/etc/analog.conf 2> /dev/null\n";
		$cron = $cron . "/root/bin/analogtidy " . $domain['bash'] . " " . $domain['domain'] . "\n";
	}

	// write file and set default permissions
	exec("echo '$cron' > /etc/cron.daily/analog");
	exec("chmod 755 /etc/cron.daily/analog");
}
?>
