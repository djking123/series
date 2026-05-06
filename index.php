<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    $db = new SQLite3('series_v3.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA cache_size = -2000'); // Use ~2MB for cache
    $db->exec('PRAGMA temp_store = MEMORY');
    
    // Initialize database tables if they don't exist
    $db->exec('CREATE TABLE IF NOT EXISTS series (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        series_name TEXT NOT NULL,
        tvdb_ID INTEGER,
        url TEXT,
        status TEXT DEFAULT "Continuing",
        artwork_url TEXT,
        last_enriched DATETIME
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS episodes (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        serie_ID INTEGER,
        episode_name TEXT,
        season_number INTEGER,
        episode_number INTEGER,
        date_aired TEXT,
        seen INTEGER DEFAULT 0,
        runtime INTEGER DEFAULT 45,
        FOREIGN KEY (serie_ID) REFERENCES series (ID) ON DELETE CASCADE
    )');

    // Create Indexes for performance
    $db->exec('CREATE INDEX IF NOT EXISTS idx_series_tvdb ON series(tvdb_ID)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_episodes_lookup ON episodes(serie_ID, season_number, episode_number)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_episodes_date ON episodes(date_aired)');

    // Migration: Add status column if it doesn't exist
    $tableInfo = $db->query("PRAGMA table_info(series)");
    $hasStatus = false;
    while ($column = $tableInfo->fetchArray(SQLITE3_ASSOC)) {
        if ($column['name'] === 'status') {
            $hasStatus = true;
            break;
        }
    }
    if (!$hasStatus) {
        $db->exec("ALTER TABLE series ADD COLUMN status TEXT DEFAULT 'Continuing'");
    }

    // Migration: Add artwork_url column if it doesn't exist
    $hasArtwork = false;
    $tableInfo = $db->query("PRAGMA table_info(series)");
    while ($column = $tableInfo->fetchArray(SQLITE3_ASSOC)) {
        if ($column['name'] === 'artwork_url') {
            $hasArtwork = true;
            break;
        }
    }
    if (!$hasArtwork) {
        $db->exec("ALTER TABLE series ADD COLUMN artwork_url TEXT");
    }

    // Migration: Add last_enriched column if it doesn't exist
    $hasLastEnriched = false;
    $tableInfo = $db->query("PRAGMA table_info(series)");
    while ($column = $tableInfo->fetchArray(SQLITE3_ASSOC)) {
        if ($column['name'] === 'last_enriched') {
            $hasLastEnriched = true;
            break;
        }
    }
    if (!$hasLastEnriched) {
        $db->exec("ALTER TABLE series ADD COLUMN last_enriched DATETIME");
    }

    // Migration: Add runtime column to episodes if it doesn't exist
    $hasRuntime = false;
    $tableInfo = $db->query("PRAGMA table_info(episodes)");
    while ($column = $tableInfo->fetchArray(SQLITE3_ASSOC)) {
        if ($column['name'] === 'runtime') {
            $hasRuntime = true;
            break;
        }
    }
    if (!$hasRuntime) {
        $db->exec("ALTER TABLE episodes ADD COLUMN runtime INTEGER DEFAULT 45");
    }
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle series updates and additions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_series'])) {
        $stmt = $db->prepare('UPDATE series SET series_name = :name, tvdb_ID = :tvdb_id, status = :status WHERE ID = :id');
        $stmt->bindValue(':name', $_POST['series_name'], SQLITE3_TEXT);
        $stmt->bindValue(':tvdb_id', $_POST['tvdb_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':status', $_POST['status'], SQLITE3_TEXT);
        $stmt->bindValue(':id', $_POST['series_id'], SQLITE3_INTEGER);
        $stmt->execute();
    } elseif (isset($_POST['toggle_seen'])) {
        $stmt = $db->prepare('UPDATE episodes SET seen = NOT seen WHERE ID = :id');
        $stmt->bindValue(':id', $_POST['episode_id'], SQLITE3_INTEGER);
        $stmt->execute();
        // Return JSON response for AJAX call
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } elseif (isset($_POST['set_season_seen'])) {
        $series_id = (int)$_POST['series_id'];
        $season_number = (int)$_POST['season_number'];
        $seen = (int)$_POST['seen'];
        $stmt = $db->prepare('UPDATE episodes SET seen = :seen WHERE serie_ID = :series_id AND season_number = :season_number');
        $stmt->bindValue(':seen', $seen, SQLITE3_INTEGER);
        $stmt->bindValue(':series_id', $series_id, SQLITE3_INTEGER);
        $stmt->bindValue(':season_number', $season_number, SQLITE3_INTEGER);
        $stmt->execute();
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } elseif (isset($_POST['add_series'])) {
        $stmt = $db->prepare('INSERT INTO series (series_name, tvdb_ID, status) VALUES (:name, :tvdb_id, :status)');
        $stmt->bindValue(':name', $_POST['series_name'], SQLITE3_TEXT);
        $stmt->bindValue(':tvdb_id', $_POST['tvdb_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':status', $_POST['status'] ?? 'Continuing', SQLITE3_TEXT);
        if ($stmt->execute()) {
            $new_id = $db->lastInsertRowID();
            $redirect_url = sprintf(
                'enrich.php?id=%d&name=%s&tvdb_id=%d',
                $new_id,
                urlencode($_POST['series_name']),
                (int)$_POST['tvdb_id']
            );
            header('Location: ' . $redirect_url);
            exit;
        } else {
            // Check if it failed because the series already exists (UNIQUE constraint)
            if (strpos($db->lastErrorMsg(), 'UNIQUE constraint failed') !== false) {
                // Find the existing series ID
                $check_stmt = $db->prepare('SELECT ID FROM series WHERE tvdb_ID = :tvdb_id');
                $check_stmt->bindValue(':tvdb_id', $_POST['tvdb_id'], SQLITE3_INTEGER);
                $existing = $check_stmt->execute()->fetchArray(SQLITE3_ASSOC);
                
                if ($existing) {
                    $redirect_url = sprintf(
                        'enrich.php?id=%d&name=%s&tvdb_id=%d',
                        $existing['ID'],
                        urlencode($_POST['series_name']),
                        (int)$_POST['tvdb_id']
                    );
                    header('Location: ' . $redirect_url);
                    exit;
                }
            }
            die("Error adding series: " . $db->lastErrorMsg());
        }
    } elseif (isset($_POST['delete_series'])) {
        $series_id = (int)$_POST['series_id'];
        // Delete all episodes for this series
        $stmt = $db->prepare('DELETE FROM episodes WHERE serie_ID = :series_id');
        $stmt->bindValue(':series_id', $series_id, SQLITE3_INTEGER);
        $stmt->execute();
        // Delete the series itself
        $stmt = $db->prepare('DELETE FROM series WHERE ID = :series_id');
        $stmt->bindValue(':series_id', $series_id, SQLITE3_INTEGER);
        $success = $stmt->execute();
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$success]);
        exit;
    }
}

// Handle AJAX request for series content (Lazy Loading)
if (isset($_GET['get_content'])) {
    $series_id = (int)$_GET['series_id'];
    
    // Get seasons
    $episodes_query = $db->prepare('SELECT DISTINCT season_number FROM episodes WHERE serie_ID = :series_id ORDER BY season_number');
    $episodes_query->bindValue(':series_id', $series_id, SQLITE3_INTEGER);
    $seasons_result = $episodes_query->execute();
    
    $output = '';
    while ($season = $seasons_result->fetchArray(SQLITE3_ASSOC)) {
        // Get seen status for all episodes in this season
        $season_seen_query = $db->prepare('SELECT COUNT(*) as total, SUM(seen) as seen_count FROM episodes WHERE serie_ID = :series_id AND season_number = :season_number');
        $season_seen_query->bindValue(':series_id', $series_id, SQLITE3_INTEGER);
        $season_seen_query->bindValue(':season_number', $season['season_number'], SQLITE3_INTEGER);
        $season_seen_result = $season_seen_query->execute()->fetchArray(SQLITE3_ASSOC);
        $all_seen = ($season_seen_result['total'] > 0 && $season_seen_result['total'] == $season_seen_result['seen_count']);

        $output .= '<div class="season-header" onclick="toggleSeason(this)">';
        $output .= '<input type="checkbox" class="season-checkbox" ' . ($all_seen ? 'checked' : '') . ' onclick="event.stopPropagation(); setSeasonSeen(' . $series_id . ', ' . $season['season_number'] . ', this.checked, this);" title="Mark all episodes in this season as seen/unseen">';
        $output .= '<span class="toggle-icon">+</span> Season ' . $season['season_number'] . '</div>';
        $output .= '<div class="season-content"><table class="episodes-table"><thead><tr><th>Episode</th><th>Title</th><th>Air Date</th><th>Status</th></tr></thead><tbody>';

        $ep_query = $db->prepare('SELECT * FROM episodes WHERE serie_ID = :series_id AND season_number = :season_number ORDER BY episode_number');
        $ep_query->bindValue(':series_id', $series_id, SQLITE3_INTEGER);
        $ep_query->bindValue(':season_number', $season['season_number'], SQLITE3_INTEGER);
        $ep_res = $ep_query->execute();

        while ($episode = $ep_res->fetchArray(SQLITE3_ASSOC)) {
            $seen_class = $episode['seen'] ? 'checked' : '';
            $output .= '<tr><td>' . $episode['episode_number'] . '</td>';
            $output .= '<td>' . htmlspecialchars_decode($episode['episode_name']) . '</td>';
            $output .= '<td>' . $episode['date_aired'] . '</td>';
            $output .= '<td><div class="checkbox-container ' . $seen_class . '" onclick="toggleSeen(' . $episode['ID'] . ', this)" title="Click to toggle watched status"></div></td></tr>';
        }
        $output .= '</tbody></table></div>';
    }
    
    if (empty($output)) {
        $output = '<p style="padding: 10px; color: var(--text-secondary);">No episodes found. Try enriching this series.</p>';
    }
    
    echo $output;
    exit;
}

$series_query = $db->query('SELECT s.*, 
    (SELECT COUNT(*) FROM episodes WHERE serie_ID = s.ID AND season_number > 0) as total_eps,
    (SELECT COUNT(*) FROM episodes WHERE serie_ID = s.ID AND season_number > 0 AND seen = 1) as seen_eps
    FROM series s 
    ORDER BY s.series_name');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Series Manager</title>
    <style>
        :root {
            --bg-primary: #121212;
            --bg-secondary: #1e1e1e;
            --bg-tertiary: #2d2d2d;
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --accent: #4CAF50;
            --accent-hover: #45a049;
            --border: #404040;
            --table-header: #333333;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            margin: 0;
            padding: 12px;
            line-height: 1.4;
            font-size: 14px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header {
            background-color: var(--bg-secondary);
            padding: 16px;
            margin-bottom: 40px;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Latest Episodes Styles */
        .latest-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 40px;
        }
        .latest-card {
            background: var(--bg-secondary);
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border);
            transition: transform 0.2s;
        }
        .latest-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
        }
        .latest-poster {
            aspect-ratio: 2/3;
            width: 100%;
            background: #222;
        }
        .latest-poster img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .latest-info {
            padding: 10px;
        }
        .latest-series {
            font-weight: bold;
            color: var(--accent);
            font-size: 0.9em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .latest-episode {
            font-size: 0.8em;
            color: var(--text-secondary);
            margin: 2px 0;
        }
        .latest-title {
            font-size: 0.85em;
            height: 2.4em;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .latest-date {
            font-size: 0.75em;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .latest-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .header h1 {
            margin: 0;
            color: var(--accent);
            font-size: 1.5em;
        }

        .search-container {
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            box-sizing: border-box;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .series-container {
            margin-bottom: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
        }

        .series-container.hidden {
            display: none;
        }

        .series-header {
            background-color: var(--bg-secondary);
            padding: 10px 16px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .series-content {
            display: none;
            padding: 20px;
            gap: 20px;
        }

        .series-info-wrapper {
            flex: 1;
            min-width: 0;
        }

        .artwork-container {
            width: 160px;
            min-width: 160px;
            height: 240px;
            background-color: var(--bg-tertiary);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0,0,0,0.4);
            display: none;
            position: relative;
            border: 1px solid var(--border);
            transition: transform 0.3s ease;
            order: 2;
        }

        .artwork-container:hover {
            transform: scale(1.05);
        }

        .artwork-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            opacity: 0;
            transition: opacity 0.5s ease;
        }

        .artwork-container img.loaded {
            opacity: 1;
        }

        .loading-spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 30px;
            height: 30px;
            border: 3px solid var(--bg-secondary);
            border-top: 3px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .series-details {
            background-color: var(--bg-secondary);
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 12px;
            font-size: 0.9em;
        }

        .series-details p {
            margin: 4px 0;
        }

        .series-form {
            display: none;
            margin-top: 12px;
            padding: 12px;
            background-color: var(--bg-tertiary);
            border-radius: 4px;
        }

        .series-form input {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border);
            color: var(--text-primary);
            padding: 6px;
            margin: 3px;
            border-radius: 4px;
            width: calc(33% - 16px);
            font-size: 0.9em;
        }

        .season-header {
            background-color: var(--bg-secondary);
            padding: 8px 16px;
            margin: 8px 0;
            cursor: pointer;
            border-radius: 4px;
        }

        .season-content {
            display: none;
            padding: 8px;
        }

        .episodes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 0.9em;
        }

        .episodes-table th {
            background-color: var(--table-header);
            padding: 8px;
            text-align: left;
            font-weight: 500;
        }

        .episodes-table td {
            padding: 8px;
            border-bottom: 1px solid var(--border);
        }

        button, .add-series-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            background-color: transparent;
            color: var(--accent);
            border: 1px solid var(--accent);
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 8px;
            font-size: 0.85em;
            transition: all 0.2s;
            text-align: center;
        }

        button:hover, .add-series-button:hover {
            background-color: var(--accent);
            color: white;
        }

        .toggle-icon {
            display: inline-block;
            width: 16px;
            text-align: center;
            font-weight: bold;
            font-size: 0.9em;
        }

        .checkbox-container {
            display: inline-block;
            width: 16px;
            height: 16px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--accent);
            border-radius: 3px;
            cursor: pointer;
            position: relative;
        }

        .checkbox-container.checked::after {
            content: '\2714';
            color: white;
            font-size: 14px;
        }

        .series-status-badge {
            font-size: 0.7em;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 10px;
            text-transform: uppercase;
            font-weight: bold;
            vertical-align: middle;
        }
        .series-status-badge.continuing {
            background-color: #2e7d32;
            color: #fff;
        }
        .series-status-badge.ended {
            background-color: #c62828;
            color: #fff;
        }
        .status-select {
            padding: 8px;
            border-radius: 4px;
            background: #2a2a2a;
            color: white;
            border: 1px solid #333;
            margin-bottom: 10px;
        }

        .url-link {
            color: var(--accent);
            text-decoration: none;
            font-size: 0.9em;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
        }

        .modal-content {
            background-color: var(--bg-secondary);
            margin: 15% auto;
            padding: 16px;
            border-radius: 6px;
            width: 90%;
            max-width: 400px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.2em;
        }

        .close-modal {
            color: var(--text-secondary);
            font-size: 24px;
            cursor: pointer;
        }

        .modal-form input {
            width: 100%;
            margin-bottom: 12px;
            padding: 6px;
            box-sizing: border-box;
        }

        .season-checkbox {
            margin-right: 10px;
            vertical-align: middle;
            accent-color: var(--accent);
        }

        @media (max-width: 768px) {
            .series-content {
                flex-direction: column;
                align-items: flex-start;
                text-align: left;
            }
            
            .artwork-container {
                order: -1;
                width: 180px;
                height: 270px;
                margin-bottom: 10px;
            }

            .series-info-wrapper {
                width: 100%;
            }

            body {
                padding: 8px;
            }
            
            .series-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .series-header > div {
                margin-top: 8px;
            }
            
            .series-form input {
                width: 100%;
                margin: 4px 0;
            }
            
            .episodes-table {
                display: block;
                overflow-x: auto;
            }
            
            .header {
                flex-direction: column;
                gap: 12px;
            }
            
            .add-series-button {
                width: 100%;
                margin: 0;
            }
            
            .modal-content {
                margin: 10% auto;
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Series Manager</h1>
        </header>

        <?php if (isset($_GET['enrich_success'])): ?>
            <div style="background: rgba(76, 175, 80, 0.2); border: 1px solid var(--accent); padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                <strong>Success!</strong> Processed <?php echo (int)$_GET['enrich_success']; ?> series updates.
            </div>
        <?php endif; ?>

        <style>
            .toggle-container {
                display: flex;
                align-items: center;
                gap: 10px;
                background: var(--bg-secondary);
                padding: 5px 15px;
                border-radius: 20px;
                border: 1px solid var(--border);
                margin-right: 15px;
            }
            .switch {
                position: relative;
                display: inline-block;
                width: 34px;
                height: 20px;
            }
            .switch input { opacity: 0; width: 0; height: 0; }
            .slider {
                position: absolute;
                cursor: pointer;
                top: 0; left: 0; right: 0; bottom: 0;
                background-color: #444;
                transition: .4s;
                border-radius: 20px;
            }
            .slider:before {
                position: absolute;
                content: "";
                height: 14px; width: 14px;
                left: 3px; bottom: 3px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
            }
            input:checked + .slider { background-color: var(--accent); }
            input:checked + .slider:before { transform: translateX(14px); }
        </style>

        <div class="header" style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 20px;">
            <div class="toggle-container">
                <span style="font-size: 0.85em; color: var(--text-secondary);">Only Continuing</span>
                <label class="switch">
                    <input type="checkbox" id="statusFilter" onchange="applyFilters()">
                    <span class="slider"></span>
                </label>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="enrich.php" class="add-series-button" style="text-decoration: none; display: flex; align-items: center;">Enrich all</a>
                <button class="add-series-button" onclick="openAddSeriesModal()">Add New Series</button>
                <button class="add-series-button" id="statsBtn" onclick="toggleStats()">Stats</button>
            </div>
        </div>

        <div id="statsSection" style="display: none; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 6px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.5);">
            <h2 style="color: var(--accent); margin-top: 0; font-size: 1.2em;">Library Statistics</h2>
            <?php
            $total_series = $db->querySingle('SELECT COUNT(*) FROM series');
            $active_series = $db->querySingle('SELECT COUNT(*) FROM series WHERE status != "Ended" OR status IS NULL');
            $total_episodes = $db->querySingle('SELECT COUNT(*) FROM episodes');
            $total_seen = $db->querySingle('SELECT COUNT(*) FROM episodes WHERE seen = 1');
            $total_unseen = $db->querySingle('SELECT COUNT(*) FROM episodes WHERE seen = 0');
            $percent = $total_episodes > 0 ? round(($total_seen / $total_episodes) * 100, 1) : 0;

            // Total Time Wasted
            $total_minutes = $db->querySingle('SELECT SUM(runtime) FROM episodes WHERE seen = 1') ?: 0;
            $days = floor($total_minutes / (24 * 60));
            $hours = floor(($total_minutes % (24 * 60)) / 60);
            $mins = $total_minutes % 60;
            $time_string = ($days > 0 ? $days . "d " : "") . ($hours > 0 ? $hours . "h " : "") . $mins . "m";

            // Top 3 Series
            $top_series_res = $db->query('SELECT s.series_name, COUNT(e.ID) as watched_count 
                                          FROM series s 
                                          JOIN episodes e ON s.ID = e.serie_ID 
                                          WHERE e.seen = 1 
                                          GROUP BY s.ID 
                                          ORDER BY watched_count DESC 
                                          LIMIT 3');
            $top_series = [];
            while ($row = $top_series_res->fetchArray(SQLITE3_ASSOC)) {
                $top_series[] = $row;
            }
            ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px;">
                <div>
                    <div style="color: var(--text-secondary); font-size: 0.9em;">Total Series</div>
                    <div style="font-size: 1.5em; font-weight: bold; color: var(--accent);"><?php echo $total_series; ?></div>
                    <div style="font-size: 0.8em; opacity: 0.7;"><?php echo $active_series; ?> Active</div>
                </div>
                <div>
                    <div style="color: var(--text-secondary); font-size: 0.9em;">Total Episodes</div>
                    <div style="font-size: 1.5em; font-weight: bold; color: var(--accent);"><?php echo $total_episodes; ?></div>
                </div>
                <div>
                    <div style="color: var(--text-secondary); font-size: 0.9em;">Completion</div>
                    <div style="font-size: 1.5em; font-weight: bold; color: var(--accent);"><?php echo $percent; ?>%</div>
                    <div style="font-size: 0.8em; opacity: 0.7;"><?php echo $total_seen; ?> Watched</div>
                </div>
                <div>
                    <div style="color: var(--text-secondary); font-size: 0.9em;">Time Spent</div>
                    <div style="font-size: 1.5em; font-weight: bold; color: var(--accent);"><?php echo $time_string; ?></div>
                    <div style="font-size: 0.8em; opacity: 0.7;">Total Watch Time</div>
                </div>
            </div>

            <?php if (!empty($top_series)): ?>
            <div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid var(--border);">
                <div style="color: var(--text-secondary); font-size: 0.9em; margin-bottom: 10px;">Most Watched Series</div>
                <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                    <?php foreach ($top_series as $idx => $s): ?>
                        <div style="background: rgba(255,255,255,0.05); padding: 8px 15px; border-radius: 20px; font-size: 0.9em; display: flex; align-items: center; gap: 8px; border: 1px solid var(--border);">
                            <span style="color: var(--accent); font-weight: bold;">#<?php echo ($idx + 1); ?></span>
                            <span><?php echo htmlspecialchars($s['series_name']); ?></span>
                            <span style="opacity: 0.6; font-size: 0.85em;">(<?php echo $s['watched_count']; ?> eps)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="search-container" style="position: relative;">
            <input 
                type="text" 
                id="seriesSearch" 
                placeholder="Search series..." 
                class="search-input"
                style="padding-right: 40px;"
            >
            <span id="clearSearch" onclick="clearSearch()" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-secondary); font-size: 18px; font-weight: bold; display: none;">&times;</span>
        </div>

        <!-- Latest Episodes Section -->
        <div id="latestAiredSection">
        <?php
        $latest_episodes = $db->query('
            SELECT 
                s.series_name,
                s.artwork_url,
                e.season_number,
                e.episode_number,
                e.episode_name,
                e.date_aired
            FROM episodes e
            JOIN series s ON e.serie_ID = s.ID
            WHERE e.date_aired != "" AND e.date_aired <= date("now")
            ORDER BY e.date_aired DESC
            LIMIT 5
        ');
        
        $ep_rows = [];
        while($row = $latest_episodes->fetchArray(SQLITE3_ASSOC)) $ep_rows[] = $row;
        
        if (!empty($ep_rows)):
        ?>
        <h2 style="color: var(--accent); margin-top: 30px; font-size: 1.2em;">Latest Aired</h2>
        <div class="latest-grid">
            <?php foreach ($ep_rows as $ep): ?>
                <div class="latest-card" onclick="jumpToSeries(<?php echo $db->querySingle('SELECT ID FROM series WHERE series_name = "' . addslashes($ep['series_name']) . '"'); ?>)" style="cursor: pointer;">
                    <div class="latest-poster">
                        <?php if ($ep['artwork_url']): ?>
                            <img src="<?php echo $ep['artwork_url']; ?>" alt="Poster">
                        <?php else: ?>
                            <div style="height:100%; display:flex; align-items:center; justify-content:center; background:#333; color:#666;">No Image</div>
                        <?php endif; ?>
                    </div>
                    <div class="latest-info">
                        <div class="latest-series"><?php echo htmlspecialchars($ep['series_name']); ?></div>
                        <div class="latest-episode">
                            S<?php echo str_pad($ep['season_number'], 2, '0', STR_PAD_LEFT); ?>E<?php echo str_pad($ep['episode_number'], 2, '0', STR_PAD_LEFT); ?>
                        </div>
                        <div class="latest-title"><?php echo htmlspecialchars_decode($ep['episode_name']); ?></div>
                        <div class="latest-date"><?php echo $ep['date_aired']; ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div>


        <!-- Add Series Modal -->
        <div id="addSeriesModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeAddSeriesModal()">&times;</span>
                <h2>Add New Series</h2>
                <form class="modal-form" method="POST" id="addSeriesForm">
                    <input type="text" name="series_name" placeholder="Series Name" required>
                    <input type="number" name="tvdb_id" placeholder="TVDB ID" required>
                    <select name="status" class="status-select" style="width: 100%; margin-bottom: 15px; padding: 10px; background: #2a2a2a; border: 1px solid #333; color: white; border-radius: 4px;">
                        <option value="Continuing">Continuing</option>
                        <option value="Ended">Ended</option>
                    </select>
                    <button type="submit" name="add_series">Add Series</button>
                </form>
            </div>
        </div>

        <?php while ($series = $series_query->fetchArray(SQLITE3_ASSOC)): ?>
            <div class="series-container" data-tvdb-id="<?php echo $series['tvdb_ID']; ?>">
                <input type="hidden" name="series_id_val" value="<?php echo $series['ID']; ?>">
                <input type="hidden" name="artwork_url_val" value="<?php echo htmlspecialchars($series['artwork_url'] ?? ''); ?>">
                <div class="series-header" onclick="toggleSeries(this)">
                    <span>
                        <span class="toggle-icon">+</span>
                        <?php echo htmlspecialchars($series['series_name']); ?>
                        <span class="series-status-badge <?php echo strtolower($series['status'] ?? 'continuing'); ?>">
                            <?php echo htmlspecialchars($series['status'] ?: 'Continuing'); ?>
                        </span>
                        <span style="font-size: 0.85em; opacity: 0.8; margin-left: 10px; font-weight: normal; color: <?php echo ($series['seen_eps'] < $series['total_eps']) ? '#4CAF50' : 'var(--text-secondary)'; ?>;">
                            Seen: <?php echo $series['seen_eps']; ?>/<?php echo $series['total_eps']; ?>
                        </span>
                    </span>
                    <div>
                        <button onclick="event.stopPropagation(); toggleEdit(<?php echo $series['ID']; ?>)">Edit</button>
                        <button onclick="event.stopPropagation(); window.location.href='enrich.php?id=<?php echo $series['ID']; ?>&name=<?php echo urlencode($series['series_name']); ?>&tvdb_id=<?php echo $series['tvdb_ID']; ?>'">
                            Enrich
                        </button>
                        <button onclick="event.stopPropagation(); confirmDeleteSeries(<?php echo $series['ID']; ?>, '<?php echo htmlspecialchars(addslashes($series['series_name'])); ?>')">Delete Series</button>
                    </div>
                </div>
                <div class="series-content">
                    <div class="artwork-container" id="artwork-<?php echo $series['ID']; ?>"></div>
                    <div class="series-info-wrapper">
                        <div class="series-details" id="details-<?php echo $series['ID']; ?>">
                            <p><strong>Series Name:</strong> <?php echo htmlspecialchars($series['series_name']); ?></p>
                            <p><strong>Status:</strong> <?php echo htmlspecialchars($series['status'] ?: 'Continuing'); ?></p>
                            <p><strong>TVDB ID:</strong> <?php echo $series['tvdb_ID']; ?></p>
                            <p><strong>Avg. Runtime:</strong> <?php 
                                $avg = $db->querySingle('SELECT AVG(runtime) FROM episodes WHERE serie_ID = ' . $series['ID'] . ' AND runtime > 0');
                                echo ($avg ? round($avg) : 45) . ' mins';
                            ?></p>
                            <p><strong>Last Enriched:</strong> <?php echo $series['last_enriched'] ?: 'Never'; ?></p>
                        </div>

                        <form class="series-form" id="form-<?php echo $series['ID']; ?>" method="POST">
                            <input type="hidden" name="series_id" value="<?php echo $series['ID']; ?>">
                            <input type="text" name="series_name" value="<?php echo htmlspecialchars($series['series_name']); ?>" placeholder="Series Name">
                            <input type="number" name="tvdb_id" value="<?php echo $series['tvdb_ID']; ?>" placeholder="TVDB ID">
                            <select name="status" class="status-select">
                                <option value="Continuing" <?php echo ($series['status'] === 'Continuing') ? 'selected' : ''; ?>>Continuing</option>
                                <option value="Ended" <?php echo ($series['status'] === 'Ended') ? 'selected' : ''; ?>>Ended</option>
                            </select>
                            <button type="submit" name="update_series">Save</button>
                            <button type="button" onclick="toggleEdit(<?php echo $series['ID']; ?>)">Cancel</button>
                        </form>

                        <div class="lazy-content" id="lazy-content-<?php echo $series['ID']; ?>">
                            <!-- Content will be loaded via AJAX -->
                            <div style="padding: 20px; text-align: center;">
                                <div class="loading-spinner" style="position: relative; display: inline-block; top: 0; left: 0; transform: none;"></div>
                                <p style="margin-top: 10px; color: var(--text-secondary);">Loading episodes...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

    <script>
        function toggleSeries(element) {
            const container = element.closest('.series-container');
            const content = element.nextElementSibling;
            const icon = element.querySelector('.toggle-icon');
            const tvdbId = container.getAttribute('data-tvdb-id');
            const seriesId = container.querySelector('input[name="series_id_val"]')?.value;
            const cachedArtwork = container.querySelector('input[name="artwork_url_val"]')?.value;
            
            if (content.style.display === 'flex' || content.style.display === 'block') {
                content.style.display = 'none';
                icon.textContent = '+';
            } else {
                // Collapse any other expanded series first (optional, but cleaner)
                document.querySelectorAll('.series-content').forEach(c => {
                    if (c !== content && (c.style.display === 'flex' || c.style.display === 'block')) {
                        c.style.display = 'none';
                        c.previousElementSibling.querySelector('.toggle-icon').textContent = '+';
                    }
                });

                // Use flex for series with artwork, block for others
                content.style.display = tvdbId ? 'flex' : 'block';
                icon.textContent = '-';
                
                // Fetch episodes content if not loaded
                if (seriesId) {
                    const lazyContainer = document.getElementById(`lazy-content-${seriesId}`);
                    if (lazyContainer && lazyContainer.getAttribute('data-loaded') !== 'true') {
                        fetch(`?get_content=1&series_id=${seriesId}`)
                            .then(response => response.text())
                            .then(html => {
                                lazyContainer.innerHTML = html;
                                lazyContainer.setAttribute('data-loaded', 'true');
                            })
                            .catch(err => console.error('Error fetching series content:', err));
                    }
                }

                // Fetch artwork if tvdbId exists and artwork hasn't been loaded
                if (tvdbId && seriesId) {
                    const artworkContainer = document.getElementById(`artwork-${seriesId}`);
                    
                    if (artworkContainer && !artworkContainer.querySelector('img')) {
                        artworkContainer.style.display = 'block';
                        
                        if (cachedArtwork && cachedArtwork !== '') {
                            // Use cached URL immediately
                            const img = new Image();
                            img.src = cachedArtwork;
                            img.alt = "Series Poster";
                            img.onload = () => {
                                artworkContainer.innerHTML = '';
                                artworkContainer.appendChild(img);
                                setTimeout(() => img.classList.add('loaded'), 10);
                            };
                        } else {
                            // Fetch and cache via API
                            artworkContainer.innerHTML = '<div class="loading-spinner"></div>';
                            fetch(`tvdb_api.php?action=get_artwork&tvdb_id=${tvdbId}&series_id=${seriesId}`)
                                .then(response => response.json())
                                .then(data => {
                                    if (data.artwork) {
                                        const img = new Image();
                                        img.src = data.artwork;
                                        img.alt = "Series Poster";
                                        img.onload = () => {
                                            artworkContainer.innerHTML = '';
                                            artworkContainer.appendChild(img);
                                            setTimeout(() => img.classList.add('loaded'), 10);
                                        };
                                        // Update the hidden input so it's "cached" for the current session
                                        const urlInput = container.querySelector('input[name="artwork_url_val"]');
                                        if (urlInput) urlInput.value = data.artwork;
                                    } else {
                                        artworkContainer.style.display = 'none';
                                    }
                                })
                                .catch(err => {
                                    console.error('Error fetching artwork:', err);
                                    artworkContainer.style.display = 'none';
                                });
                        }
                    }
                }
            }
        }

        function toggleSeason(element) {
            const content = element.nextElementSibling;
            const icon = element.querySelector('.toggle-icon');
            if (content.style.display === 'block') {
                content.style.display = 'none';
                icon.textContent = '+';
            } else {
                content.style.display = 'block';
                icon.textContent = '-';
            }
        }

        function toggleEdit(seriesId) {
            const details = document.getElementById(`details-${seriesId}`);
            const form = document.getElementById(`form-${seriesId}`);
            const container = details.closest('.series-container');
            const header = container.querySelector('.series-header');
            const content = container.querySelector('.series-content');
            
            // If the series isn't expanded, expand it
            if (content.style.display === 'none' || content.style.display === '') {
                toggleSeries(header);
            }

            if (details.style.display === 'none') {
                details.style.display = 'block';
                form.style.display = 'none';
            } else {
                details.style.display = 'none';
                form.style.display = 'block';
            }
        }

        function openAddSeriesModal() {
            document.getElementById('addSeriesModal').style.display = 'block';
        }

        function closeAddSeriesModal() {
            document.getElementById('addSeriesModal').style.display = 'none';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('addSeriesModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        function toggleSeen(episodeId, element) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `toggle_seen=1&episode_id=${episodeId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    element.classList.toggle('checked');
                }
            });
        }

        function setSeasonSeen(seriesId, seasonNumber, checked, checkboxElem) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `set_season_seen=1&series_id=${seriesId}&season_number=${seasonNumber}&seen=${checked ? 1 : 0}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update all checkboxes in this season
                    // Find the parent .season-header, then its next sibling .season-content
                    const seasonHeader = checkboxElem.closest('.season-header');
                    const seasonContent = seasonHeader.nextElementSibling;
                    if (seasonContent) {
                        const episodeCheckboxes = seasonContent.querySelectorAll('.checkbox-container');
                        episodeCheckboxes.forEach(cb => {
                            if (checked) {
                                cb.classList.add('checked');
                            } else {
                                cb.classList.remove('checked');
                            }
                        });
                    }
                }
            });
        }

        function applyFilters() {
            const searchInput = document.getElementById('seriesSearch');
            const searchTerm = searchInput.value.toLowerCase();
            const showOnlyContinuing = document.getElementById('statusFilter').checked;
            const seriesContainers = document.querySelectorAll('.series-container');
            const latestSection = document.getElementById('latestAiredSection');
            const clearBtn = document.getElementById('clearSearch');
            
            // Hide/Show latest aired section
            if (latestSection) {
                latestSection.style.display = searchTerm.length > 0 ? 'none' : 'block';
            }

            // Show/Hide clear button
            if (clearBtn) {
                clearBtn.style.display = searchTerm.length > 0 ? 'block' : 'none';
            }

            seriesContainers.forEach(container => {
                // Skip the "Nog te kijken" container
                const headerText = container.querySelector('.series-header').textContent.trim();
                if (headerText === 'Nog te kijken') return;
                
                const seriesTitle = headerText.toLowerCase().replace('+', '').trim();
                
                // Get status from badge
                const statusBadge = container.querySelector('.series-status-badge');
                const isEnded = statusBadge && statusBadge.textContent.trim().toLowerCase() === 'ended';
                
                const matchesSearch = seriesTitle.includes(searchTerm);
                const matchesStatus = !showOnlyContinuing || !isEnded;

                if (matchesSearch && matchesStatus) {
                    container.classList.remove('hidden');
                } else {
                    container.classList.add('hidden');
                }
            });
        }

        function searchSeries() {
            applyFilters();
        }

        // Initialize search functionality and restore state
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('seriesSearch');
            searchInput.addEventListener('input', searchSeries);
            
            // Apply initial filters (defaults to Only Continuing)
            applyFilters();

            // Restore expanded series (ONLY from URL param)
            const urlParams = new URLSearchParams(window.location.search);
            const expandedId = urlParams.get('expanded_id');
            
            if (expandedId) {
                const seriesInput = document.querySelector(`input[name="series_id"][value="${expandedId}"]`);
                if (seriesInput) {
                    const container = seriesInput.closest('.series-container');
                    const header = container.querySelector('.series-header');
                    if (header) {
                        toggleSeries(header);
                        // Scroll to the series
                        setTimeout(() => {
                            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 500); // Wait for expansion animation/loading
                    }
                }
            }
        });

        function toggleStats() {
            const stats = document.getElementById('statsSection');
            const btn = document.getElementById('statsBtn');
            if (stats.style.display === 'none') {
                stats.style.display = 'block';
                btn.style.backgroundColor = 'var(--accent)';
                btn.style.color = 'white';
            } else {
                stats.style.display = 'none';
                btn.style.backgroundColor = 'transparent';
                btn.style.color = 'var(--accent)';
            }
        }

        function clearSearch() {
            const input = document.getElementById('seriesSearch');
            input.value = '';
            searchSeries();
            input.focus();
        }

        function jumpToSeries(seriesId) {
            if (!seriesId) return;
            
            // Find the series header
            const seriesInput = document.querySelector(`input[name="series_id_val"][value="${seriesId}"]`);
            if (seriesInput) {
                const container = seriesInput.closest('.series-container');
                const header = container.querySelector('.series-header');
                const content = container.querySelector('.series-content');
                
                // If already expanded, just scroll
                if (content.style.display === 'flex' || content.style.display === 'block') {
                    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    // Expand and then scroll
                    toggleSeries(header);
                    setTimeout(() => {
                        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 300);
                }
            }
        }

        function confirmDeleteSeries(seriesId, seriesName) {
            if (confirm(`Are you sure you want to delete the series "${seriesName}" and all its episodes? This action cannot be undone.`)) {
                deleteSeries(seriesId);
            }
        }

        function deleteSeries(seriesId) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `delete_series=1&series_id=${seriesId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove the series container from the DOM
                    const container = document.querySelector(`.series-container button[onclick*="confirmDeleteSeries(${seriesId},"]`).closest('.series-container');
                    if (container) {
                        container.remove();
                    }
                } else {
                    alert('Failed to delete series.');
                }
            });
        }
    </script>
</body>
</html>