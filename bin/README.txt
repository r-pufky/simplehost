Simplehost
Copyright 2006, Robert M. Pufky



Simplehost:
-----------
We needed to run a secure webserver with multiple hosts on it. Seeing
as how there was no projects at the time that made this easy in debian; 
I decided to write my own.

Features include:
* automatic addition/deletion of hosted domains
* automatic backup of domain files/databases
* automatic configuration of logged facilities
* automatic configuration of Gallery2 installations
* user level password changes
* user level apache2 reloading with error checking
* user level subdomain creation
* user level msql export and import facilities
* user level help system to aid with commands

This has been a personal favorite of mine, simply because it includes 
scripts interacting with each other on every level, in a secure 
fashion. Such that users can only run commands on their own domains, 
even when running sudo.



Installation:
-------------
Install Gallery2, mysql, apache2.

run bin/install to install the administration scripts.  All commands
and usage are documented in the program, with it's own help system.



Legal Issues:
-------------
You may use this program for personal use only!  If you want to 
use any of the code provided herein for commerical use (i.e. you will
make money from it) please contact me at: robert.pufky@gmail.com
Chances are I'll just let you use it, just ask!  If you ask after
you've already done it, I won't be too happy about it

Even though throughly tested, there could be bugs.  I hold no
liability for damages incurred using this.  Use at your own risk!!
