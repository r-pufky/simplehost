#!/usr/bin/php4
<?
// Robert Pufky
// 2006-06-13 - User subdomain creation script

// constants
$program = "create_subdomain";
$die = "Usage:\n\tcreate_subdomain\n\n\tSubdomain is the subdomain without the domain:\n\t\tfruity.abc.com --enter-this-> 'fruity'\n\nNO subdomains modified.\n\n";

// we must be root to run this script
if( exec("whoami") != "root" ) {
	die("Must be root to run this script.\n");
}

// setup db and logging, and error messaging
require("config.inc.php");

// find out if we were launched by helper process, and determine domain name
if( $_SERVER['argc'] != 2 ) { mlog(0,"addme",$fatal,"Invalid command line arguments."); }
$domainname = str_replace("-",".",strtolower($argv[1]));

// if the domain doesn't exist, error; else grab pertinent information
if( !$domain = mysql_fetch_array(mquery("select * from domains where domain='" . $domainname . "'")) ) { mlog(0,"main",$fatal,"Cannot retrieve database name."); }
$user = $domain['username'];
$IP = $domain['ip'];
$did = $domain['id'];

// prompt for subdomain name, and verify it doesn't exist already
echo "\n\nEnter desired subdomain [i.e. test.abc.com -> test]: ";
$subdomain = getinput();
if( $results = mysql_fetch_array(mquery("select * from subdomains where subdomain='$subdomain'")) ) { mlog($did,"main",$fatal,"Subdomain $subdomain.$domainname already exists!"); }
if( file_exists("/home/$user/www/$subdomain") ) { mlog($did,"main",$fatal,"Directory exists where subdomain would be created: /home/$user/www/$subdomain."); }

// update database
mquery("insert into subdomains (did,subdomain) values('" . $domain['id'] . "','" . $subdomain . "')");
mlog($did,"main",!$fatal,"Created subdomain $subdomain.$domainname sql entry");

// create hosting directory
exec("mkdir /home/$user/www/$subdomain");
exec("chown $user:www-data /home/$user/www/$subdomain");
echo("\nCreated subdomain hosting directory.");

// Create the virtualhost template for apache2
exec("echo '
NameVirtualHost $IP:80
<VirtualHost $IP:80>
  ServerName $subdomain.$domainname
  ServerAdmin webmaster@$subdomain.$domainname
  DocumentRoot /home/$user/www/$subdomain
  ErrorLog /home/$user/logs/$subdomain.$domainname-error.log
  CustomLog /home/$user/logs/$subdomain.$domainname-access.log common
</VirtualHost>

# Note: by default, site uses generic SSL Cert.
# Remove this line, change and uncomment the .crt & .key lines for custom certificate
NameVirtualHost $IP:443
<VirtualHost $IP:443>
  SSLEngine On
  SSLCertificateFile /etc/apache2/ssl/apache.pem
  # Custom Certificates MUST be loaded by admins.
  #SSLCertificateFile /home/$login/etc/<your cert>.crt
  #SSLCertificateKeyFile /home/$login/etc/<your cert>.key
  SSLProtocol all
  SSLCipherSuite HIGH:MEDIUM
  ServerName $subdomain.$domainname
  ServerAdmin webmaster@$subdomain.$domainname
  DocumentRoot /home/$user/www/$subdomain
  ErrorLog /home/$user/logs/ssl-$subdomain.$domainname-error.log
  CustomLog /home/$user/logs/ssl-$subdomain.$domainname-access.log common
</VirtualHost>' >> /home/$user/etc/apache.conf");
echo("\nCreated subdomain apache configuration file");

// setup logfiles
exec("touch /home/$user/logs/$subdomain.$domainname-error.log /home/$user/logs/$subdomain.$domainname-access.log");
exec("chmod 660 /home/$user/logs/$subdomain.$domainname-error.log /home/$user/logs/$subdomain.$domainname-access.log");
echo("Reloading apache2... ");
exec("/etc/init.d/apache2 reload");
echo "done.\n\nCreated '$subdomain.$domainname'.\n\tIf configuration needs to be changed, contact admin to reload the subdomain.\n\n";
mlog($did,"main",!$fatal,"Created $subdomain.$domainname successfully.");
?>
