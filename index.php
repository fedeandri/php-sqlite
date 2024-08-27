<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$databaseFile = 'database.sqlite';
$maxCacheSeconds = 300;
$maxSecondsPerTest = 3;

// Initialize database connection
try {
    $db = new SQLite3($databaseFile);
    $db->enableExceptions(true);
    
    // Enable Write-Ahead Logging for better concurrency and performance
    $db->exec('PRAGMA journal_mode = WAL;');
    // Increase cache size to approximately 100MB (-25000 pages, where each page is 4KB)
    $db->exec('PRAGMA cache_size = -25000;');
    // Set synchronous mode to NORMAL for a balance between safety and performance
    // Set synchronous mode to FULL for maximum durability at the cost of performance
    $db->exec('PRAGMA synchronous = NORMAL;');
    // Store temporary tables and indices in memory instead of on disk
    $db->exec('PRAGMA temp_store = MEMORY;');
    // Set the maximum size of the memory-mapped I/O to approximately 1GB
    $db->exec('PRAGMA mmap_size = 1000000000;');
    // Enable foreign key constraints for data integrity
    $db->exec('PRAGMA foreign_keys = true;');
    // Set a busy timeout of 5 seconds to wait if the database is locked
    $db->exec('PRAGMA busy_timeout = 5000;');
    // Enable incremental vacuuming to reclaim unused space and keep the database file size optimized
    $db->exec('PRAGMA auto_vacuum = INCREMENTAL;');
    // Use STRICT on table creation for better data integrity
    $db->exec("
        CREATE TABLE IF NOT EXISTS test_results_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            result TEXT NOT NULL,
            timestamp INTEGER NOT NULL
        ) STRICT
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_test_results_cache_timestamp ON test_results_cache (timestamp)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author TEXT NOT NULL,
            content TEXT NOT NULL,
            test_session TEXT NOT NULL,
            timestamp INTEGER NOT NULL
        ) STRICT
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_comments_timestamp ON comments (timestamp)");
    
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage() . 
        ' (DB Path: ' . $databaseFile . 
        ', Directory Writable: ' . (is_writable(dirname($databaseFile)) ? 'Yes' : 'No') . 
        ', PHP User: ' . get_current_user() . 
        ')');
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    ob_start();

    try {
        switch ($_GET['action']) {
            case 'runTest':
                echo json_encode(getCachedTest($db));
                break;
            case 'getSpecs':
                echo json_encode(getServerSpecs());
                break;
            default:
                echo json_encode(['error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }

    $output = ob_get_clean();
    echo (json_decode($output) === null) ? json_encode(['error' => 'PHP Error: ' . $output]) : $output;
    exit;
}

/**
 * Get server specs information
 * @return array specs information
 */
function getServerSpecs() {
    $serverSpecs = [
        'vCPUs' => 1,
        'CPU model' => 'Unknown',
        'Platform' => php_uname('s') . ', ' . php_uname('m') . ', ' . php_uname('r'),
        'Total RAM' => 'Unknown',
        'CPU usage' => 'Unknown',
        'Memory usage' => 'Unknown',
    ];

    // Get CPU model and count
    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        preg_match('/model name\s+:\s+(.+)$/m', $cpuinfo, $matches);
        $serverSpecs['CPU model'] = $matches[1] ?? 'Unknown';
        $serverSpecs['vCPUs'] = substr_count($cpuinfo, 'processor') ?: 2;
    }

    // Get total RAM
    if (is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $matches)) {
            $serverSpecs['Total RAM'] = round($matches[1] / 1024 / 1024, 1) . 'GB';
        }
    }

    // Get CPU usage
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $serverSpecs['CPU usage'] = round(($load[0] / $serverSpecs['vCPUs']) * 100, 1) . '%';
    }

    // Get memory usage
    if (function_exists('memory_get_usage') && $serverSpecs['Total RAM'] !== 'Unknown') {
        $used_memory = memory_get_usage(true);
        $total_memory = floatval($serverSpecs['Total RAM']) * 1024 * 1024 * 1024;
        $serverSpecs['Memory usage'] = round(($used_memory / $total_memory) * 100, 1) . '%';
    }

    return $serverSpecs;
}

/**
 * Get cached database test results or run a new test
 * @param SQLite3 $db Database connection
 * @return array Test results
 */
function getCachedTest($db) {

    global $maxCacheSeconds;

    // Use a prepared statement for better caching of query plans
    $stmt = $db->prepare('SELECT * FROM test_results_cache ORDER BY timestamp DESC LIMIT 1');
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($result && (time() - $result['timestamp'] < $maxCacheSeconds)) {
        $decodedResult = json_decode($result['result'], true);
        return $decodedResult;
    }

    $testResult = runTest($db);
    
    // Delete all older records
    $db->exec('DELETE FROM test_results_cache');

    // Store the new result in the database
    $stmt = $db->prepare('INSERT INTO test_results_cache (result, timestamp) VALUES (:result, :timestamp)');
    $stmt->bindValue(':result', json_encode($testResult), SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->execute();

    return $testResult;
}

/**
 * Run database performance test
 * @param SQLite3 $db Database connection
 * @return array Test results
 */
function runTest($db) {
    global $databaseFile, $maxSecondsPerTest;

    $maxDuration = $maxSecondsPerTest; // Maximum test duration in seconds
    $chunkSize = 100;
    $testSessionId = uniqid('test_', true);
    $currentTimestamp = time();

    // Prepare statements
    $insertStmt = $db->prepare('INSERT INTO comments (author, content, test_session, timestamp) VALUES (:author, :content, :test_session, :timestamp)');
    $selectStmt = $db->prepare('SELECT * FROM comments WHERE id = :id');
    $updateStmt = $db->prepare('UPDATE comments SET content = :content WHERE id = :id');
    $deleteStmt = $db->prepare('DELETE FROM comments WHERE id = :id');

    // Write test
    $writeStart = microtime(true);
    $writes = 0;
    $newRecords = [];
    while (microtime(true) - $writeStart < $maxDuration) {
        $db->exec('BEGIN TRANSACTION');
        for ($j = 0; $j < $chunkSize; $j++) {
            $author = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, rand(5, 20));
            $content = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, rand(10, 100));
            $insertStmt->bindValue(':author', $author, SQLITE3_TEXT);
            $insertStmt->bindValue(':content', $content, SQLITE3_TEXT);
            $insertStmt->bindValue(':test_session', $testSessionId, SQLITE3_TEXT);
            $insertStmt->bindValue(':timestamp', $currentTimestamp, SQLITE3_INTEGER);
            if ($insertStmt->execute()) {
                $newRecords[] = $db->lastInsertRowID();
                $writes++;
            }
        }
        $db->exec('COMMIT');
    }
    $writeTime = microtime(true) - $writeStart;
    $writesPerSecond = round($writes / $writeTime);

    // Read test
    $readStart = microtime(true);
    $reads = 0;
    $readDuration = 0;
    while ($readDuration < $maxDuration) {
        $id = $newRecords[array_rand($newRecords)];
        $selectStmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $selectStmt->execute();
        $reads++;
        $readDuration = microtime(true) - $readStart;
    }
    $readsPerSecond = round($reads / $readDuration);

    // Update test
    $updateStart = microtime(true);
    $updates = 0;
    $updateDuration = 0;
    while ($updateDuration < $maxDuration) {
        $id = $newRecords[array_rand($newRecords)];
        $newContent = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, rand(10, 100));
        $updateStmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $updateStmt->bindValue(':content', $newContent, SQLITE3_TEXT);
        $updateStmt->execute();
        $updates++;
        $updateDuration = microtime(true) - $updateStart;
    }
    $updatesPerSecond = round($updates / $updateDuration);

    // Delete test
    $deleteStart = microtime(true);
    $deletes = 0;
    $deleteDuration = 0;
    while ($deleteDuration < $maxDuration && !empty($newRecords)) {
        $id = array_pop($newRecords);
        $deleteStmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $deleteStmt->execute();
        $deletes++;
        $deleteDuration = microtime(true) - $deleteStart;
    }
    $deletesPerSecond = round($deletes / $deleteDuration);

    // Clean up remaining test data
    $db->exec("DELETE FROM comments WHERE test_session = '$testSessionId'");

    $dbSizeInMb = round(filesize($databaseFile) / 1024 / 1024, 2);

    $totalOperations = $writes + $reads + $updates + $deletes;
    $operationsPerSecond = round($totalOperations / ($maxDuration * 4)); // Multiply by 4 because there are 4 test phases
    
    return [
        'dbSizeInMb' => $dbSizeInMb,
        'totalOperations' => $totalOperations,
        'operationsPerSecond' => $operationsPerSecond,
        'writes' => $writes,
        'writesPerSecond' => $writesPerSecond,
        'reads' => $reads,
        'readsPerSecond' => $readsPerSecond,
        'updates' => $updates,
        'updatesPerSecond' => $updatesPerSecond,
        'deletes' => $deletes,
        'deletesPerSecond' => $deletesPerSecond,
        'duration' => round(microtime(true) - $writeStart, 2),
    ];
}

$db->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>PHP 8.3 + Vanilla JavaScript / SQLite Performance Test</title>
	<style>
		body {
            display: flex;
			justify-content: center;
			align-items: center;
			font-family: Arial, sans-serif;
			background-color: white;
			margin: 0;
			padding: 0;
		}
		main {
			display: flex;
			min-height: 100vh;
            width: 100%;
            max-width: 1200px;
			flex-direction: column;
			padding: 2.5rem;
		}
		h1 {
			font-weight: bold;
			font-size: 1.25rem;
			margin-bottom: 1.5rem;
		}
		p {
			margin-bottom: 2.5rem;
            line-height: 1.4em;
		}
		a {
			color: #2563eb;
			text-decoration: none;
			text-underline-offset: 4px;
		}
		.container {
			display: flex;
			flex-direction: column;
			gap: 1.5rem;
		}
		@media (min-width: 640px) {
			.container {
				flex-direction: row;
				gap: 2.5rem;
			}
		}
		.card {
			background-color: white;
			padding: 1.5rem;
			border-radius: 0.5rem;
			box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
			flex: 1;
            margin-bottom: 1rem;
		}
		.card h2 {
			font-size: 1.125rem;
			font-weight: 600;
			margin-bottom: 0;
		}
		.spinner {
			width: 20px;
			height: 20px;
            margin-top: 1rem;
			border: 2px solid #f3f3f3;
			border-top: 2px solid #2563eb;
			border-radius: 50%;
			animation: spin 1s linear infinite;
		}
		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}
		.flex-row {
			display: flex;
			flex-direction: row;
			align-items: center;
			gap: 0.25rem;
		}
		.navbar {
			display: flex;
			flex-wrap: wrap;
			justify-content: space-between;
			background-color: #f3f4f6;
			padding: 0.5rem;
			border-radius: 0.25rem;
			border: 1px solid #d3d3d3;
			margin-bottom: 1.5rem;
		}
		.navbar a {
			text-decoration: none;
			color: #4b5563;
			padding: 0.5rem 1rem;
			border-radius: 0.25rem;
            border: 1px solid #d3d3d3;
			transition: background-color 0.3s ease;
			margin: 0.25rem;
		}
		.navbar a:hover {
			background-color: #d1d5db;
			color: black !important;
		}
		.navbar a.active {
			background-color: #2563eb;
            border: 1px solid #2563eb;
			color: white !important;
		}
	</style>
</head>
<body>
	<main>
		<nav class="navbar">
			<a href="https://php-sqlite.xpressionist.com" class="active">PHP / SQLite Test</a>
			<a href="https://node-sqlite.xpressionist.com">Node / SQLite Test</a>
			<a href="https://nextjs-sqlite.xpressionist.com">Next.js / SQLite Test</a>
			<a href="https://sveltekit-sqlite.xpressionist.com">SvelteKit / SQLite Test</a>
		</nav>
        <h1>PHP 8.3 + Vanilla JavaScript / SQLite Performance Test</h1>
		<p>
			Built by <a href="https://x.com/fedeandri">@fedeandri</a> starting from
			<a href="https://x.com/ashleyrudland/status/1826991719646179583"
			  target="_blank"
			  rel="noopener noreferrer">@ashleyrudland</a>'s PHP code. See the <strong>source</strong> of this
			<a href="https://github.com/fedeandri/php-sqlite"
			  target="_blank"
			  rel="noopener noreferrer">PHP / SQLite Test on Github</a>.
              <br /> <s>From my SQLite tests <strong>PHP writes are ~10x faster and reads are ~40x faster</strong> than Node.</s>
			<br />NEW RESULTS: <strong>Node writes are ~2x faster</strong> than PHP and <strong>PHP reads are ~1.2x faster</strong> than Node.
			<br />--
			<br />I wanted to run more comprehensive tests, so I added updates and deletes. Then, to test a scenario closer to real life usage, I added indexes, more data variation, and tweaked SQLite for better data durability other than pure performance (thanks to <a href="https://x.com/jasonleowsg" target="_blank" rel="noopener noreferrer">@jasonleowsg</a> for pointing me to <a href="https://x.com/meln1k/status/1813314113705062774" target="_blank" rel="noopener noreferrer">this post</a>). For Node-based apps, I switched from sqlite3 to <a href="https://www.npmjs.com/package/better-sqlite3" target="_blank" rel="noopener noreferrer">better-sqlite3</a> (thanks to <a href="https://x.com/theSlavenIvanov" target="_blank" rel="noopener noreferrer">@theSlavenIvanov</a> for the recommendation), and this really levels the playing field.
		</p>
		<div class="container">
			<div class="card">
				<h2>PHP / SQLite Test</h2>
                <small>(5 minutes cache)</small>
				<div id="results-box">
					<div class="flex-row">
						<div class="spinner"></div>
						<span>Running test (<span id="runningTime">0.0</span>s)...</span>
					</div>
				</div>
			</div>
			<div class="card">
            <h2>VPS Specs</h2>
            <small>(<a href="https://www.vultr.com/pricing/" target="_blank">Vultr High Performance Intel $12/mo</a> with <a href="https://www.cloudpanel.io/" target="_blank">CloudPanel</a>)</small>
			<div id="specs-box">
                <div class="flex-row">
                    <div class="spinner"></div>
                    <span>Loading specs data...</span>
                </div>
            </div>
        </div>
		</div>
	</main>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			let startTime;
			let timer;

			function updateRunningTime() {
				if (startTime) {
					let runningTime = (Date.now() - startTime) / 1000;
					document.getElementById('runningTime').textContent = runningTime.toFixed(1);
				}
			}

			function getSpecs() {
				fetch('?action=getSpecs')
					.then(response => response.json())
					.then(result => {
						let content = '<ul>';
						for (let [key, value] of Object.entries(result)) {
							content += `<li>${key}: ${value}</li>`;
						}
						content += '</ul>';
						document.getElementById('specs-box').innerHTML = content;
					})
					.catch(error => {
						document.getElementById('specs-box').innerHTML = `<p>Error: ${error}</p>`;
					});
			}

			function runTest() {
				startTime = Date.now();
				timer = setInterval(updateRunningTime, 200);

				fetch('?action=runTest')
					.then(response => response.json())
					.then(result => {
						clearInterval(timer);
						let content = '<ul>';
						content += `<li>Duration: ${result.duration ? result.duration.toLocaleString() : 'N/A'} seconds</li>`;
						content += `<li>DB size: ${result.dbSizeInMb ? (result.dbSizeInMb >= 1024 ? (result.dbSizeInMb / 1024).toLocaleString(undefined, { maximumFractionDigits: 1 }) + 'GB' : result.dbSizeInMb.toLocaleString() + 'MB') : 'N/A'}</li>`;
						content += `<li>Total operations: ${result.totalOperations ? result.totalOperations.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Operations/sec: ${result.operationsPerSecond ? result.operationsPerSecond.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Writes: ${result.writes ? result.writes.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Writes/sec: ${result.writesPerSecond ? result.writesPerSecond.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Reads: ${result.reads ? result.reads.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Reads/sec: ${result.readsPerSecond ? result.readsPerSecond.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Updates: ${result.updates ? result.updates.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Updates/sec: ${result.updatesPerSecond ? result.updatesPerSecond.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Deletes: ${result.deletes ? result.deletes.toLocaleString() : 'N/A'}</li>`;
						content += `<li>Deletes/sec: ${result.deletesPerSecond ? result.deletesPerSecond.toLocaleString() : 'N/A'}</li>`;
						content += '</ul>';
						document.getElementById('results-box').innerHTML = content;
					})
					.catch(error => {
						clearInterval(timer);
						document.getElementById('results-box').innerHTML = `<p>Error: ${error}</p>`;
					});
			}

			runTest();
			getSpecs();
		});
	</script>
</body>
</html>