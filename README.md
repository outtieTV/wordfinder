# Word Finder by outtieTV
An Open-Source Self-Hosted Word Finder<br />
<br />
On Windows/Mac/Linux:
1. Download Word Finder source code and unzip it somewhere
2. Install PHP
3. cd to wordfinder directory that contains index.html
4. Download a wordlist.txt of your choosing to the above directory
5. Run fix-dictionaries.py with the wordlist.txt if needed
6. Choose either word_finder.php for sqlite or word_finder_sql.php for mysql 8+
7. Rename word_finder_sql.php to scrabble_word_finder.php if using sql mode.
8. Edit word_finder.php in a text editor and change the line that says $WORDLIST_FILE = __DIR__ . "/CSW24_modified.txt";
9. Run php -S 0.0.0.0:80
10. Open browser to localhost:80/word_finder.php to generate scrabbleAnagrams.sqlite or scrabbleDB
11. Navigate to localhost:80 to use the word finder.
<br />
<br />
ScrabbleWordFinder on Termux on Android:<br />
1. Download F-Droid<br />
2. Download Termux through F-Droid<br />
3. Open Termux<br />
4. $ termux-setup-storage<br />
5. $ termux-change-repo<br />
6. $ pkg update && pkg upgrade<br />
7. $ pkg install php sqlite<br />
8. $ pkg install wget<br />
9. $ cd ~<br />
12. $ wget https://github.com/outtieTV/wordfinder/archive/refs/heads/main.zip<br />
13. $ unzip main.zip<br />
14. $ chmod -R a+wx wordfinder-main<br />
15. $ cd wordfinder-main<br />
16. $ ifconfig -a<br />
17. $ termux-wake-lock<br />
18. $ php -S 0.0.0.0:8000<br />
19. To make it as a termux-service:<br />
  $ mkdir -p $PREFIX/var/service/wordfinder/log && printf '%s\n' '#!/bin/sh' 'cd /data/data/com.termux/files/home/wordfinder-main || exit 1' 'export PATH="$PATH:$PREFIX/bin"' 'exec php -S 0.0.0.0:8000' > $PREFIX/var/service/wordfinder/run && printf '%s\n' '#!/bin/sh' 'exec svlogd -tt .' > $PREFIX/var/service/wordfinder/log/run && chmod +x $PREFIX/var/service/wordfinder/run $PREFIX/var/service/wordfinder/log/run<br />
20. Same thing as the command in step 18: sv-enable wordfinder && sv start wordfinder<br />
<br /><br />
You can find word dictionaries at: https://boardgames.stackexchange.com/questions/38366/latest-collins-scrabble-words-list-in-text-file
<img width="1920" height="910" alt="image" src="https://github.com/user-attachments/assets/d72c71c9-d0ae-41b1-8235-10f8ef577551" />
<br />
<br />
Edit to mention: if you want to easily keep track of Scrabble scores, use Microsoft Excel or Google Sheets and type =SUM(A:A) to sum a whole column of scores or =SUM(A2:A) to ignore headers for names.<br /><br />
<br />
HASBRO, its logo, and SCRABBLE® are trademarks of Hasbro in the U.S. and Canada. All content copyright its respective owners.
