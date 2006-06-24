<?
/* 
  Copyright 2006, Robert Pufky
  Written 06-22-2006

  Admin functions for scripts

  load after config.inc.php/functions.inc.php
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
	mque("insert into domains (domain,ip,name,email,password,mysql,bash,gallery2) values('$domain','$ip','$name','$mail','changeme','$mysql','$bash','N')");
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
	exec("touch /home/$bash/logs/$domain-error.log /home/$bash/logs/$domain-access.log");
	exec("touch /home/$bash/logs/ssl-$domain-error.log /home/$bash/logs/ssl-$domain-access.log");
	echo("\nCreated base directories and configuration files.");
	
	// secure directories and files
	exec("chmod -R o-rwx /home/$bash");
	exec("chmod o+rx /home/$bash");
	exec("chmod -R 750 /home/$bash/backup /home/$bash/www /home/$bash/etc");
	exec("chmod 660 /home/$bash/etc/apache.conf");
	exec("chmod 600 /home/$bash/logs/*");
	exec("chown -R $bash:www-data /home/$bash/www /home/$bash/etc");
	exec("chown -R $bash:$bash /home/$bash/backup /home/$bash/logs");
	echo("\nSecured directories and configuration files.");

	// link to apache2 master configuration directory
	exec("ln -s /home/$bash/etc/apache.conf /etc/apache2/sites-available/$domain");

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
	exec("/root/bin/passwd-noninteractive.exp $bash changeme > /dev/null");
	exec("ifup eth0:" . $results['id']);
	exec("a2ensite $domain");
	exec("/etc/init.d/apache2 reload");
	echo "done!\n\n";
	mlog("hostmodify.add",!FATAL,"$domain added successfuly!");
	echo "\n\nLogin username:   $bash\nDB Username:      $mysql\nDB Name:          $mysql\nDefault password: changeme\n\n";
}

// Function: delete
// Purpose:  delete a domain on the system
// Requires: string - the domain to be deleted
function deleteme($domain) {
	global $rootpass;
	
	mlog("hostmodify.delete",!FATAL,"Request to delete $domain.");
	$results = mysql_fetch_array(mque("select * from domains where domain='$domain'"));
	
	// disable the interface / website
	exec("a2dissite $domain");
	exec("ifdown eth0:" . $results['id']);
	exec("rm /etc/apache2/sites-available/$domain");

	// delete the user account and home directory
	exec("deluser " . $results['bash']);
	exec("rm -rf /home/" . $results['bash']);

	// delete the user database / user, and reload mysql
	exec("mysql -u root -p$rootpass -e 'drop database " . $results['mysql'] . "'");
	exec("mysql -u root -p$rootpass -e \"delete from mysql.user where Host='localhost' and User='" . $results['mysql'] . "'\"");
	exec("mysql -u root -p$rootpass -e \"flush privileges\"");

	// remove from sudoers file, apache port configuration, and network configuration
	remove($results['domain'],0,"/etc/sudoers");
	remove($results['ip'],0,"/etc/apache2/ports.conf");
	remove("iface eth0:" . $results['id'] . " inet static",2,"/etc/network/interfaces");

	// remove domain from database and reload apache
	mquery("delete from ports where did='" . $results['id'] . "'");
	mquery("delete from subdomains where did='" . $results['id'] . "'");
	mquery("delete from domains where id='" . $results['id'] . "'");
	exec("/etc/init.d/apache2 reload");
	
	mlog("hostmodify.delete",!FATAL,"Deleted $domain.");
}

// Function: write_apache2
// Purpose:  writes the apache2 configuration file to specified location
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
  ErrorLog /home/$login/logs/ssl-$domainname-error.log
  CustomLog /home/$login/logs/ssl-$domainname-access.log common
</VirtualHost>' > /home/$login/etc/apache.conf");
}

// Function: write_sudoers
// Purpose:  writes the sudoers configuration to the sudoers file
// Requires: string - login name of the user
function write_sudoers($login) {
	exec("echo '$login    ALL = NOPASSWD: /root/bin/change_pass.php $login,/root/bin/create_subdomain.php $login' >> /etc/sudoers");
}

// Function: write_ports
// Purpose:  write the apache2 port configuration to the ports.conf file
// Requires: string - the database id of the domain
//           string - valid IP of the domain
function write_ports($id,$ip) {
	$results = mque("select * from ports where did='" . $id . "'");

	while( $port = mysql_fetch_array($results) ) {
		exec("echo -e -n '\nListen $ip:" . $port['port'] . "\n' >> /etc/apache2/ports.conf");
	}
}

// Function: write_interfaces
// Purpose:  write the given interface to the interfaces file
// Requires: string - valid IP address to add
//           integer - the number of the interface to add
function write_interfaces($ip,$num) {
	exec("echo -e -n '\n\niface eth0:$num inet static\naddress $ip\nnetmask 255.255.255.0' >> /etc/network/interfaces");
}
?>
