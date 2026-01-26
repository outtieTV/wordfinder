<?php
// index.php - Scrabble Word Finder
// PHP logic starts here to avoid 'headers already sent' error.

// ---- CONFIG ----
$dbname = "scrabbleAnagrams.sqlite";
$WORDLIST_FILE = __DIR__ . "/NWL2023_modified.txt"; // Server admin must provide this file

// ---- Check for AJAX request and execute backend logic ----
if (isset($_GET['json']) && $_GET['json'] === '1') {
    header("Content-Type: application/json");

    // ---- DB INIT ----
    try {
        $conn = new PDO("sqlite:" . $dbname, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $e) {
        die(json_encode(["error" => "DB connection failed. Check file permissions for the directory ($dbname)."]));
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS words (
            id INTEGER PRIMARY KEY,
            word VARCHAR(32) UNIQUE,
            length TINYINT,
            score SMALLINT
        )
    ");

    // ---- WORDLIST PRE-LOADING HELPER ----
    function scrabbleScore($word) {
        $scores = [
            'a' => 1, 'b' => 3, 'c' => 3, 'd' => 2, 'e' => 1, 'f' => 4, 'g' => 2, 'h' => 4, 'i' => 1, 'j' => 8,
            'k' => 5, 'l' => 1, 'm' => 3, 'n' => 1, 'o' => 1, 'p' => 3, 'q' => 10, 'r' => 1, 's' => 1, 't' => 1,
            'u' => 1, 'v' => 4, 'w' => 4, 'x' => 8, 'y' => 4, 'z' => 10,
        ];
        $score = 0;
        foreach (str_split($word) as $letter) {
            $score += $scores[$letter] ?? 0;
        }
        return $score;
    }

    $res = $conn->query("SELECT COUNT(*) AS c FROM words");
    $row = $res->fetch(PDO::FETCH_ASSOC);

    if ($row['c'] == 0) {
        if (!is_readable($WORDLIST_FILE)) {
            die(json_encode(["error" => "Wordlist not found: $WORDLIST_FILE"]));
        }
        
        // Initial load: insert all words
        $fh = fopen($WORDLIST_FILE, "r");
        $conn->beginTransaction();
        $stmt = $conn->prepare("INSERT OR IGNORE INTO words (word,length,score) VALUES (?,?,?)");

        $insert_count = 0;
        while (($line = fgets($fh)) !== false) {
            $w = strtolower(trim($line));
            if ($w === "" || preg_match('/[^a-z]/', $w)) continue;

            $len = strlen($w);
            $score = scrabbleScore($w);

            $stmt->execute([$w, $len, $score]);
            $insert_count++;
        }
        $conn->commit();
        fclose($fh);
    }

    // ---- CONCURRENCY LIMIT (4 users max) ----
    $lockFile = sys_get_temp_dir() . "/scrabble_finder.lock";
    $maxSlots = 4;

    $lockHandle = fopen($lockFile, "c+");
    if (!$lockHandle) {
        // Fallback if lock fails
    } else {
        flock($lockHandle, LOCK_EX);
        $slots = (int)stream_get_contents($lockHandle);

        if ($slots >= $maxSlots) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            echo json_encode(["error" => "Server busy, please wait and try again."]);
            exit;
        } else {
            ftruncate($lockHandle, 0);
            rewind($lockHandle);
            fwrite($lockHandle, $slots + 1);
            fflush($lockHandle);
            flock($lockHandle, LOCK_UN);
        }
    }


    // ---- HELPER: Release concurrency slot ----
    function releaseSlot($file, $handle) {
        if (!$handle) return;
        flock($handle, LOCK_EX);
        fseek($handle, 0);
        $slots = (int)stream_get_contents($handle);
        $slots = max(0, $slots - 1);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $slots);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    // ---- HELPER: Check if word can be formed (FIXED LOGIC) ----
    function countLetters($str) {
        $letters = [];
        $str = str_replace(' ', '', $str); 
        for ($i = 0; $i < strlen($str); $i++) {
            $ch = $str[$i];
            $letters[$ch] = ($letters[$ch] ?? 0) + 1;
        }
        return $letters;
    }

    function canFormWord($word, $rack) {
        $rackLetters = countLetters($rack);
        $substitutions = [];
        $blanks = $rackLetters['?'] ?? 0;
        
        for ($i = 0; $i < strlen($word); $i++) {
            $letter = $word[$i];
            
            if (isset($rackLetters[$letter]) && $rackLetters[$letter] > 0) {
                // Use a physical tile
                $rackLetters[$letter]--;
            } elseif ($blanks > 0) {
                // Use a blank tile and track the substitution (0-indexed position)
                $blanks--;
                $substitutions[$i] = $letter; 
            } else {
                return [false, false]; // Cannot form the word
            }
        }
        
        // Return array: [substitutions array, canForm: true]
        return [$substitutions, true]; 
    }

    // ---- REQUEST HANDLING ----
    $rack = isset($_GET["rack"]) ? strtolower(preg_replace("/[^a-z?]/", "", $_GET["rack"])) : "";
    
    // New Text Filters
    $starts_with = isset($_GET["start"]) ? strtolower(preg_replace("/[^a-z]/", "", $_GET["start"])) : "";
    $ends_with = isset($_GET["end"]) ? strtolower(preg_replace("/[^a-z]/", "", $_GET["end"])) : "";
    $contains = isset($_GET["contains"]) ? strtolower(preg_replace("/[^a-z]/", "", $_GET["contains"])) : "";

    // Position Filters
    $positions = isset($_GET["pos"]) && is_array($_GET["pos"]) ? $_GET["pos"] : [];
    $pos_letters = isset($_GET["letter"]) && is_array($_GET["letter"]) ? $_GET["letter"] : [];

    // Allow search if rack is empty but any filter is active. Stop only if ALL are empty.
    $has_filters = strlen($starts_with) > 0 || strlen($ends_with) > 0 || strlen($contains) > 0 || count($positions) > 0;

    if (!$rack && !$has_filters) {
        releaseSlot($lockFile, $lockHandle);
        // Return empty result instead of crashing
        echo json_encode(["results" => []]);
        exit;
    }
    
    // ---- BUILD SQL QUERY and DETERMINE TILE POOL ----
    $sql_base = "SELECT word, length, score FROM words WHERE 1=1"; 
    $params = [];
    $fixed_letters_pool = ""; // Used for the "concatenated rack"

    // 1. Add STARTS WITH filter
    if (strlen($starts_with) > 0) {
        $sql_base .= " AND word LIKE :start_pattern";
        $params[':start_pattern'] = $starts_with . "%";
    }

    // 2. Add ENDS WITH filter
    if (strlen($ends_with) > 0) {
        $sql_base .= " AND word LIKE :end_pattern";
        $params[':end_pattern'] = "%" . $ends_with;
    }

    // 3. Add CONTAINS filter
    if (strlen($contains) > 0) {
        $sql_base .= " AND word LIKE :contains_pattern";
        $params[':contains_pattern'] = "%" . $contains . "%";
    }
    
    // 4. Position Filters
    $num_filters = min(count($positions), count($pos_letters));
    for ($i = 0; $i < $num_filters; $i++) {
        $position = (int)$positions[$i];
        $pos_letter = strtolower(preg_replace("/[^a-z]/", "", $pos_letters[$i]));

        if ($position >= 1 && $position <= 15 && strlen($pos_letter) === 1) {
            // Apply hard constraint on word structure
            $sql_base .= " AND SUBSTR(word, :pos{$i}, 1) = :letter{$i}";
            $params[":pos{$i}"] = $position;
            $params[":letter{$i}"] = $pos_letter;
            
            // Concatenate fixed letter to the tile pool
            $fixed_letters_pool .= $pos_letter; 
        }
    }

    // 5. Determine the full tile pool and max word length
    $combined_rack = $rack . $fixed_letters_pool;
    
    // If the rack is empty, allow a large word length for pattern searches (up to 15, the max board size)
    $max_word_len = ($rack || $fixed_letters_pool) ? strlen($combined_rack) : 15; 

    // Add length constraint
    $sql_base .= " AND length <= :max_len";
    $params[':max_len'] = $max_word_len;
    
    // Ensure min length is at least the length of the longest fixed pattern
    $min_len = 0;
    if (strlen($starts_with) > $min_len) $min_len = strlen($starts_with);
    if (strlen($ends_with) > $min_len) $min_len = strlen($ends_with);
    if (strlen($contains) > $min_len) $min_len = strlen($contains);
    if ($num_filters > 0) $min_len = max($min_len, 1); // If a position filter is set, min length is 1

    if ($min_len > 0) {
         $sql_base .= " AND length >= :min_len";
         $params[':min_len'] = $min_len;
    }


    $sql_base .= " ORDER BY length DESC, word ASC";

    // ---- FETCH CANDIDATE WORDS ----
    $stmt = $conn->prepare($sql_base);
    $stmt->execute($params);

    $groupedCandidates = [];
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Only perform `canFormWord` check if the user provided letters (rack)
            if ($rack) {
                list($substitutions, $canForm) = canFormWord($row["word"], $combined_rack);
            } else {
                // If rack is empty, we assume all words passing the SQL filters are valid
                $canForm = true; 
                $substitutions = [];
            }
            
            if ($canForm) {
                $len = $row['length'];
                if (!isset($groupedCandidates[$len])) {
                    $groupedCandidates[$len] = [];
                }
                
                $word_data = $row;
                if (!empty($substitutions)) {
                    $word_data['blank_substitutions'] = $substitutions; 
                }
                
                $groupedCandidates[$len][] = $word_data;
            }
        }
    }

    // ---- RELEASE SLOT ----
    releaseSlot($lockFile, $lockHandle);

    // ---- OUTPUT ----
    echo json_encode([
        "results"  => $groupedCandidates
    ]);
    exit;
}
// If not an AJAX request, continue to output HTML.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Scrabble Word Finder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
  /* --- CSS for Mobile/Desktop Friendliness --- */
  body {
  font-family: Arial, sans-serif;
  margin: 1.5em 1em;
  background: #f2f4f7;
  color: #333;
}

h1 {
  text-align: center;
  font-size: 2em;
  margin-bottom: 1em;
  color: #2c3e50;
}

#form-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
}

input[type="text"], input[type="number"] {
  font-size: 1.2em;
  padding: 12px;
  width: 100%;
  max-width: 400px;
  border-radius: 10px;
  border: 1px solid #ccc;
  box-sizing: border-box;
  text-transform: lowercase;
  outline: none;
  transition: border 0.2s;
}

input[type="text"]:focus, input[type="number"]:focus {
  border-color: #007bff;
}

button {
  padding: 12px;
  font-size: 1.1em;
  width: 100%;
  max-width: 400px;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  background: #007bff;
  color: white;
  transition: background 0.2s;
}

button:hover {
  background: #0056b3;
}

/* Specific button styles */
#toggle-advanced-button {
  background: #3498db; 
  max-width: 400px;
  width: 100%;
  margin-top: -5px;
  padding: 10px 12px;
  font-size: 1em;
}

#toggle-advanced-button:hover {
  background: #2980b9;
}

#clear-all-button {
  background: #e67e22; /* Orange color for reset */
}
#clear-all-button:hover {
  background: #d35400;
}

/* Container for advanced filters - initially hidden */
#advanced-filters-container {
  display: none; 
  flex-direction: column;
  gap: 10px;
  width: 100%;
  max-width: 400px;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

#advanced-filters-container.active {
    display: flex;
}

/* Row for the three text filters */
.text-filter-row {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.text-filter-row input {
    font-size: 1em;
    padding: 10px;
    width: 100%;
    max-width: none;
    margin: 0;
}

/* Button for adding position filters */
#add-position-button {
  background: #2ecc71; 
  max-width: 400px;
  width: 100%;
  padding: 10px 12px;
  font-size: 1em;
}

#add-position-button:hover {
  background: #27ae60;
}


/* Container for dynamically added position filters */
#position-list {
  display: flex;
  flex-direction: column;
  gap: 5px;
  width: 100%;
  max-width: 400px;
  margin-top: 5px;
}

.position-row {
  display: flex;
  gap: 5px;
  align-items: center;
  background: #ecf0f1;
  padding: 5px;
  border-radius: 5px;
}

.position-row input {
  padding: 8px;
  font-size: 0.9em;
  height: 40px;
  max-width: 30%;
  width: 30%;
}

.position-row button {
  padding: 8px;
  font-size: 0.9em;
  max-width: 25%;
  width: 25%;
  height: 40px;
  background: #e74c3c; /* Red for remove */
}
.position-row button:hover {
  background: #c0392b;
}


/* Center results container */
#results-container {
  width: 100%;
  max-width: 600px;
  margin: 0 auto;
  padding: 0 10px;
  box-sizing: border-box;
}

/* Collapsible styles (rest of the CSS is unchanged) */
.collapsible {
  background-color: #fff;
  color: #2c3e50;
  cursor: pointer;
  padding: 16px;
  width: 100%;
  border: none;
  text-align: left;
  outline: none;
  font-size: 1.1em;
  transition: 0.3s;
  border-radius: 10px;
  box-shadow: 0 3px 6px rgba(0,0,0,0.1);
  margin-top: 15px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.collapsible span.count {
  font-weight: normal;
  color: #007bff;
  background: #e6f0ff;
  padding: 4px 8px;
  border-radius: 5px;
}

.collapsible:after {
  content: '\25BC';
  font-size: 16px;
  color: #777;
  margin-left: 10px;
  transition: transform 0.2s;
}

.collapsible.active:after {
  content: '\25B2';
  transform: rotate(180deg);
}

.content {
  padding: 10px 0 12px 0;
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.3s ease;
  background-color: #fff;
  border-radius: 0 0 10px 10px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
  margin-bottom: 10px;
  overflow-x: auto;
}

/* Table styles */
.word-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 5px;
  font-size: 0.95em;
  min-width: 300px;
}

.word-table th, .word-table td {
  padding: 10px 12px;
  text-align: center;
  border-bottom: 1px solid #eee;
}

.word-table th {
  background-color: #f2f2f2;
  font-weight: bold;
  color: #333;
  position: sticky;
  top: 0;
}

.word-table tr:hover {
  background-color: #f6f6f6;
}

/* Wiktionary link style override to remove underline from word */
.word-table a {
    text-decoration: none;
    color: inherit;
}
.word-table a:hover {
    text-decoration: underline;
    color: #007bff;
}

/* Highlighted letter for blank tile */
.blank-substitute {
  font-weight: bold;
  color: #d9534f; 
  text-decoration: underline;
}

/* Pagination buttons */
.pagination {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 5px;
  margin-top: 15px;
  padding: 0 10px;
}

.pagination button {
  padding: 6px 10px;
  border-radius: 5px;
  border: 1px solid #007bff;
  background: white;
  color: #007bff;
  cursor: pointer;
  font-size: 0.9em;
  transition: 0.2s;
  width: auto; 
  max-width: none;
}

.pagination button:hover:not(:disabled) {
  background: #007bff;
  color: white;
}

.pagination button.active {
  background: #007bff;
  color: white;
}

.pagination button:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}


@media (max-width: 650px) {
  .word-table th:nth-child(2), .word-table td:nth-child(2) {
      display: none; 
  }
}

@media (max-width: 480px) {
  h1 {
    font-size: 1.6em;
  }

  input[type="text"], input[type="number"], button {
    font-size: 1em;
    padding: 10px;
  }
  
  .collapsible {
    padding: 12px 14px;
    font-size: 1em;
  }

  .position-row input {
    width: 35%;
    max-width: 35%;
  }

  .position-row button {
    width: 20%;
    max-width: 20%;
  }
}
  </style>
  <script>
/* --- JavaScript for Word Search and UI --- */
const perPage = 50;
let allResults = {};
let currentPage = {};
let filterCounter = 0; // Used to uniquely ID filter rows
const MAX_POSITIONS = 15;

document.addEventListener("DOMContentLoaded", () => {
    // --- Attach all event listeners ---
    const rackInput = document.getElementById("rackInput");
    const findButton = document.getElementById("find-words-button");
    const clearAllButton = document.getElementById("clear-all-button"); // NEW
    const advancedToggle = document.getElementById("toggle-advanced-button");
    const addPositionButton = document.getElementById("add-position-button");

    rackInput.addEventListener("keypress", e => {
      if (e.key === "Enter") { e.preventDefault(); findWords(); }
    });

    findButton.addEventListener("click", findWords);
    clearAllButton.addEventListener("click", clearAllInputs); // NEW
    advancedToggle.addEventListener("click", toggleAdvancedFilters);
    addPositionButton.addEventListener("click", () => {
        addPositionInput();
        findWords(); 
    });

    // Run search on Enter key for all new text fields
    document.getElementById("startsWithInput").addEventListener("keypress", e => { if (e.key === "Enter") findWords(); });
    document.getElementById("endsWithInput").addEventListener("keypress", e => { if (e.key === "Enter") findWords(); });
    document.getElementById("containsInput").addEventListener("keypress", e => { if (e.key === "Enter") findWords(); });


    // Initial load: check URL for filters and add them
    loadFiltersFromURL();
});

// NEW: Function to clear all inputs
function clearAllInputs() {
    document.getElementById("rackInput").value = "";
    document.getElementById("startsWithInput").value = "";
    document.getElementById("endsWithInput").value = "";
    document.getElementById("containsInput").value = "";
    
    // Clear dynamic position inputs
    document.getElementById("position-list").innerHTML = "";

    // Clear results display
    document.getElementById("results-container").innerHTML = "";
    
    allResults = {};
    currentPage = {};
    
    // Focus on the main rack input
    document.getElementById("rackInput").focus();
}


function toggleAdvancedFilters() {
    const container = document.getElementById('advanced-filters-container');
    container.classList.toggle('active');
    
    // Optional: Update button text
    const button = document.getElementById('toggle-advanced-button');
    if (container.classList.contains('active')) {
        button.textContent = 'HIDE ADVANCED FILTERS';
    } else {
        button.textContent = 'ADVANCED FILTERS';
    }
}

// Helper to remove a position filter row
function removePositionInput(id) {
    const row = document.getElementById(id);
    if (row) {
        row.remove();
        // Trigger search update after removing a filter
        findWords(); 
    }
}

// Helper to add a new position filter row
function addPositionInput(pos = '', letter = '') {
    const list = document.getElementById('position-list');
    
    if (list.children.length >= MAX_POSITIONS) {
        alert("Maximum of " + MAX_POSITIONS + " position filters reached.");
        return;
    }
    
    const rowId = 'filter-row-' + filterCounter++;
    
    const row = document.createElement('div');
    row.className = 'position-row';
    row.id = rowId;
    
    // Position Input (1-15)
    const posInput = document.createElement('input');
    posInput.type = 'number';
    posInput.min = '1';
    posInput.max = '15';
    posInput.placeholder = 'Pos (1-15)';
    posInput.value = pos;
    posInput.className = 'position-input';
    posInput.addEventListener('keypress', e => {
        if (e.key === 'Enter') { e.preventDefault(); findWords(); }
    });
    
    // Letter Input (single letter)
    const letterInput = document.createElement('input');
    letterInput.type = 'text';
    letterInput.maxLength = '1';
    letterInput.placeholder = 'Letter';
    letterInput.value = letter;
    letterInput.className = 'letter-input';
    letterInput.addEventListener('keypress', e => {
        if (e.key === 'Enter') { e.preventDefault(); findWords(); }
    });
    
    // Remove Button
    const removeBtn = document.createElement('button');
    removeBtn.textContent = 'REMOVE';
    removeBtn.onclick = () => removePositionInput(rowId);
    
    // Append elements to the row
    row.appendChild(posInput);
    row.appendChild(letterInput);
    row.appendChild(removeBtn);
    
    list.appendChild(row);

    // If a filter is added via URL, make sure the advanced container is visible
    if (pos !== '' || letter !== '') {
        document.getElementById('advanced-filters-container').classList.add('active');
    }
}

function loadFiltersFromURL() {
    const urlParams = new URLSearchParams(window.location.search);
    const rack = urlParams.get('rack');
    
    // Load new text filters
    const start = urlParams.get('start');
    const end = urlParams.get('end');
    const contains = urlParams.get('contains');
    
    let filtersPresent = false;
    
    if (start) {
        document.getElementById('startsWithInput').value = start;
        filtersPresent = true;
    }
    if (end) {
        document.getElementById('endsWithInput').value = end;
        filtersPresent = true;
    }
    if (contains) {
        document.getElementById('containsInput').value = contains;
        filtersPresent = true;
    }
    
    // Load position filters
    const posArray = urlParams.getAll('pos[]');
    const letterArray = urlParams.getAll('letter[]');
    
    const numFilters = Math.min(posArray.length, letterArray.length);
    for(let i = 0; i < numFilters; i++) {
        addPositionInput(posArray[i], letterArray[i]);
        filtersPresent = true;
    }
    
    // If any advanced filters were loaded, toggle the container open
    if (filtersPresent) {
        toggleAdvancedFilters();
    }
    
    // If a rack was provided, run the search
    if (rack) {
        document.getElementById('rackInput').value = rack;
        findWords();
    }
}

async function findWords() {
    const rack = document.getElementById("rackInput").value.trim().toLowerCase();
    
    // 1. Read all filter values
    const start = document.getElementById('startsWithInput').value.trim().toLowerCase();
    const end = document.getElementById('endsWithInput').value.trim().toLowerCase();
    const contains = document.getElementById('containsInput').value.trim().toLowerCase();

    const positionInputs = document.querySelectorAll('#position-list > .position-row');
    let fixedPos = [];
    let fixedLetter = [];

    positionInputs.forEach(row => {
        const pos = row.querySelector('.position-input').value.trim();
        const letter = row.querySelector('.letter-input').value.trim().toLowerCase();
        
        // Basic validation and population for fixedPos
        const posNum = parseInt(pos);
        if (posNum >= 1 && posNum <= 15 && letter.length === 1 && letter.match(/[a-z]/)) {
            fixedPos.push(posNum);
            fixedLetter.push(letter);
        }
    });

    // Check if RACK is empty AND no advanced filters are active
    if (!rack && !start && !end && !contains && fixedPos.length === 0) {
        alert("Please enter some letters in the rack OR use at least one advanced filter (Starts With, Ends With, Contains, or Position).");
        return;
    }
	if ((rack && start) || (rack && end) || (rack && contains)) {
		alert("Do not use rack with start/end/contains.");
		return;
	}

    
    // 2. Build URL with array parameters for PHP
    let url = "index.php?rack=" + encodeURIComponent(rack) + "&json=1";
    
    // Add text filters
    if (start) url += "&start=" + encodeURIComponent(start);
    if (end) url += "&end=" + encodeURIComponent(end);
    if (contains) url += "&contains=" + encodeURIComponent(contains);
    
    // Add position filters
    fixedPos.forEach((pos, index) => {
        url += "&pos[]=" + encodeURIComponent(pos);
        url += "&letter[]=" + encodeURIComponent(fixedLetter[index]);
    });
    
    // 3. Fetch data
    const response = await fetch(url);
    
    if (!response.ok) {
        alert("Error fetching data. Server responded with: " + response.status);
        return;
    }
    const data = await response.json();
    
    if (data.error) {
        alert(data.error);
        return;
    }
    
    // 4. Render results
    allResults = data.results;
    currentPage = {}; 
    renderResults();
}

function renderResults() {
    const container = document.getElementById("results-container");
    container.innerHTML = "";

    const lengths = Object.keys(allResults).sort((a, b) => b - a);
    
    if (lengths.length === 0) {
        container.innerHTML = "<p style='text-align:center;'>No results found.</p>";
        return;
    }

    lengths.forEach(len => {
        const words = allResults[len];
        if (words.length === 0) return;

        const button = document.createElement("button");
        button.className = "collapsible";
        button.innerHTML = `${len}-Letter Words <span class="count">${words.length}</span>`;

        const contentDiv = document.createElement("div");
        contentDiv.className = "content";

        const table = document.createElement("table");
        table.className = "word-table";
        table.innerHTML = `
            <thead>
                <tr>
                    <th>Word</th>
                    <th>Length</th>
                    <th>Score</th>
                </tr>
            </thead>
            <tbody></tbody>
        `;
        
        const paginationDiv = document.createElement("div");
        paginationDiv.className = "pagination";
        
        contentDiv.appendChild(table);
        contentDiv.appendChild(paginationDiv);

        container.appendChild(button);
        container.appendChild(contentDiv);

        contentDiv.style.maxHeight = "0px";

        button.onclick = function() {
            const isActive = this.classList.toggle("active");
            if (isActive) {
                showPage(contentDiv, len, 1);
            } else {
                contentDiv.style.maxHeight = "0px";
            }
        };
        
        if (window.innerWidth <= 480) {
            contentDiv.style.maxHeight = "0px";
            button.classList.remove("active");
        }
    });
}

function showPage(contentDiv, length, page) {
    const words = allResults[length];
    const tableBody = contentDiv.querySelector('.word-table tbody');
    const paginationDiv = contentDiv.querySelector('.pagination');

    tableBody.innerHTML = "";
    paginationDiv.innerHTML = "";

    const offset = (page - 1) * perPage;
    const paginatedWords = words.slice(offset, offset + perPage);

    if (paginatedWords.length === 0) {
        tableBody.innerHTML = `<tr><td colspan="3" style="text-align:center;">No results found.</td></tr>`;
    } else {
        paginatedWords.forEach(item => {
            const row = document.createElement("tr");
            
            // Get the word HTML (with blanks highlighted)
            const wordContentHtml = highlightBlanks(item.word, item.blank_substitutions || {});
            
            // NEW: Wrap the word HTML in an anchor tag pointing to Wiktionary
            const wordLinkHtml = `<a href="https://en.wiktionary.org/wiki/${item.word}" target="_blank">${wordContentHtml}</a>`;

            row.innerHTML = `
                <td>${wordLinkHtml}</td>
                <td>${item.length}</td>
                <td>${item.score}</td>
            `;
            tableBody.appendChild(row);
        });
    }

    currentPage[length] = page;
    renderPagination(paginationDiv, contentDiv, length, page, words.length);

    contentDiv.style.maxHeight = contentDiv.scrollHeight + "px";
}

// Helper to highlight blank tile substitutions
function highlightBlanks(word, substitutions) {
    let html = '';
    for (let i = 0; i < word.length; i++) {
        const char = word[i];
        if (substitutions[i] === char) {
            html += `<span class="blank-substitute">${char}</span>`;
        } else {
            html += char;
        }
    }
    return html;
}


function renderPagination(container, contentDiv, length, page, totalWords) {
    const totalPages = Math.ceil(totalWords / perPage);
    if (totalPages <= 1) return;

    const prevBtn = document.createElement("button");
    prevBtn.textContent = "Prev";
    prevBtn.disabled = page === 1;
    prevBtn.onclick = () => showPage(contentDiv, length, page - 1);
    container.appendChild(prevBtn);

    const maxButtons = 5; 
    let start = Math.max(1, page - Math.floor(maxButtons / 2));
    let end = Math.min(totalPages, start + maxButtons - 1);

    if (end - start < maxButtons - 1) start = Math.max(1, end - maxButtons + 1);
    
    if (start > 1) {
        const btnOne = document.createElement("button");
        btnOne.textContent = 1;
        btnOne.onclick = () => showPage(contentDiv, length, 1);
        container.appendChild(btnOne);
        if (start > 2) {
            const ellipsis = document.createElement("span");
            ellipsis.textContent = "...";
            ellipsis.style.padding = "6px 5px";
            container.appendChild(ellipsis);
        }
    }

    for (let i = start; i <= end; i++) {
        const btn = document.createElement("button");
        btn.textContent = i;
        if (i === page) btn.classList.add("active");
        btn.onclick = () => showPage(contentDiv, length, i);
        container.appendChild(btn);
    }
    
    if (end < totalPages) {
        if (end < totalPages - 1) {
            const ellipsis = document.createElement("span");
            ellipsis.textContent = "...";
            ellipsis.style.padding = "6px 5px";
            container.appendChild(ellipsis);
        }
        const btnLast = document.createElement("button");
        btnLast.textContent = totalPages;
        btnLast.onclick = () => showPage(contentDiv, length, totalPages);
        container.appendChild(btnLast);
    }


    const nextBtn = document.createElement("button");
    nextBtn.textContent = "Next";
    nextBtn.disabled = page === totalPages;
    nextBtn.onclick = () => showPage(contentDiv, length, page + 1);
    container.appendChild(nextBtn);
}
  </script>
</head>
<body>
  <h1>Scrabble Word Finder</h1>
  <div id="form-container">
    <input type="text" id="rackInput" maxlength="15" placeholder="Enter your letters (up to 15, use ? for blank)">
    
    <button id="toggle-advanced-button">ADVANCED FILTERS</button>
    
    <div id="advanced-filters-container">
        
        <div class="text-filter-row">
            <input type="text" id="startsWithInput" placeholder="STARTS WITH">
            <input type="text" id="endsWithInput" placeholder="ENDS WITH">
            <input type="text" id="containsInput" placeholder="CONTAINS">
        </div>

        <button id="add-position-button">+ ADD POSITION</button>
        
        <div id="position-list">
            </div>

    </div>
    
    <button id="find-words-button">Find Words</button>
    
    <button id="clear-all-button">Clear All Inputs</button>
  </div>
  <div id="results-container"></div>
</body>
</html>
