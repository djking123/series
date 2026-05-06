<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once 'tvdb_api.php';

// Database Initialization
try {
    $db = new SQLite3('series_v3.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->exec('PRAGMA encoding = "UTF-8"');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA cache_size = -2000');
    $db->exec('PRAGMA temp_store = MEMORY');

    // Schema Setup/Migrations
    $db->exec('CREATE TABLE IF NOT EXISTS series (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        series_name TEXT NOT NULL,
        tvdb_ID INTEGER,
        url TEXT,
        status TEXT DEFAULT "Continuing",
        artwork_url TEXT,
        last_enriched TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS episodes (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        serie_ID INTEGER NOT NULL,
        episode_name TEXT,
        season_number INTEGER,
        episode_number INTEGER,
        date_aired TEXT,
        seen INTEGER DEFAULT 0,
        runtime INTEGER DEFAULT 45,
        FOREIGN KEY (serie_ID) REFERENCES series (ID) ON DELETE CASCADE
    )');

    // Ensure status column exists in series
    $res = $db->query("PRAGMA table_info(series)");
    $cols = []; while($r = $res->fetchArray(SQLITE3_ASSOC)) $cols[] = $r['name'];
    if (!in_array('status', $cols)) $db->exec("ALTER TABLE series ADD COLUMN status TEXT DEFAULT 'Continuing'");
    if (!in_array('last_enriched', $cols)) $db->exec("ALTER TABLE series ADD COLUMN last_enriched TEXT");
    if (!in_array('artwork_url', $cols)) $db->exec("ALTER TABLE series ADD COLUMN artwork_url TEXT");

    // Ensure runtime column exists in episodes
    $res = $db->query("PRAGMA table_info(episodes)");
    $cols = []; while($r = $res->fetchArray(SQLITE3_ASSOC)) $cols[] = $r['name'];
    if (!in_array('runtime', $cols)) $db->exec("ALTER TABLE episodes ADD COLUMN runtime INTEGER DEFAULT 45");

} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper Functions
function insertEpisode($db, $series_id, $season, $episode, $title, $air_date, $runtime = 45) {
    $stmt = $db->prepare('INSERT INTO episodes (serie_ID, episode_name, season_number, episode_number, date_aired, seen, runtime) 
                         VALUES (:series_id, :title, :season, :episode, :air_date, 0, :runtime)');
    $stmt->bindValue(':series_id', $series_id, SQLITE3_INTEGER);
    $stmt->bindValue(':title', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), SQLITE3_TEXT);
    $stmt->bindValue(':season', $season, SQLITE3_INTEGER);
    $stmt->bindValue(':episode', $episode, SQLITE3_INTEGER);
    $stmt->bindValue(':air_date', $air_date, SQLITE3_TEXT);
    $stmt->bindValue(':runtime', (int)$runtime ?: 45, SQLITE3_INTEGER);
    return $stmt->execute();
}

function updateEpisode($db, $episode_id, $title, $air_date, $runtime = 45) {
    $stmt = $db->prepare('UPDATE episodes SET episode_name = :title, date_aired = :air_date, runtime = :runtime WHERE ID = :id');
    $stmt->bindValue(':title', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), SQLITE3_TEXT);
    $stmt->bindValue(':air_date', $air_date, SQLITE3_TEXT);
    $stmt->bindValue(':runtime', (int)$runtime ?: 45, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $episode_id, SQLITE3_INTEGER);
    return $stmt->execute();
}

$tvdb = new TVDB();
$series_to_process = [];

// Determine Mode: Single or Bulk
$single_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($single_id > 0) {
    $stmt = $db->prepare('SELECT * FROM series WHERE ID = :id');
    $stmt->bindValue(':id', $single_id, SQLITE3_INTEGER);
    $series_to_process[] = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
} else {
    // Bulk mode: 10 oldest non-ended, skipping those enriched in the last 24 hours
    $res = $db->query("SELECT * FROM series 
                       WHERE (status != 'Ended' OR status IS NULL) 
                       AND (last_enriched IS NULL OR last_enriched < datetime('now', '-24 hours', 'localtime')) 
                       ORDER BY last_enriched ASC, series_name ASC 
                       LIMIT 100");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $series_to_process[] = $row;
    }
}

$all_changes = []; // Format: [series_id => ['name' => ..., 'data' => [...]]]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_changes'])) {
    try {
        // Process Changes
        $db->exec('BEGIN TRANSACTION');
        
        $processed_count = 0;
        
        // 1. Process Status Updates
        if (isset($_POST['status_upd'])) {
            foreach ($_POST['status_upd'] as $s_id => $new_status) {
                $stmt = $db->prepare('UPDATE series SET status = :status WHERE ID = :id');
                $stmt->bindValue(':status', $new_status, SQLITE3_TEXT);
                $stmt->bindValue(':id', $s_id, SQLITE3_INTEGER);
                if (!$stmt->execute()) throw new Exception("Failed to update status for series $s_id");
            }
        }

        // 2. Process Additions
        if (isset($_POST['add'])) {
            foreach ($_POST['add'] as $s_id => $episodes_json) {
                foreach ($episodes_json as $json) {
                    $ep = json_decode($json, true);
                    if (!insertEpisode($db, $s_id, $ep['season'], $ep['number'], $ep['title'], $ep['aired'], $ep['runtime'])) {
                        throw new Exception("Failed to insert episode for series $s_id");
                    }
                }
            }
        }

        // 3. Process Updates
        if (isset($_POST['update'])) {
            foreach ($_POST['update'] as $ep_id => $json) {
                $data = json_decode($json, true);
                if (!updateEpisode($db, $ep_id, $data['title'], $data['date'], $data['runtime'] ?? 45)) {
                    throw new Exception("Failed to update episode $ep_id");
                }
            }
        }

        // 4. Process Removals
        if (isset($_POST['remove'])) {
            foreach ($_POST['remove'] as $ep_id) {
                $stmt = $db->prepare('DELETE FROM episodes WHERE ID = :id');
                $stmt->bindValue(':id', $ep_id, SQLITE3_INTEGER);
                if (!$stmt->execute()) throw new Exception("Failed to delete episode $ep_id");
            }
        }

        // 5. Finalize: Update timestamps and artwork for all involved series
        foreach ($_POST['tvdb_ids'] as $s_id => $t_id) {
            $artwork = $tvdb->getSeriesArtwork((int)$t_id);
            if ($artwork) {
                $stmt = $db->prepare('UPDATE series SET artwork_url = :url, last_enriched = datetime("now", "localtime") WHERE ID = :id');
                $stmt->bindValue(':url', $artwork, SQLITE3_TEXT);
                $stmt->bindValue(':id', $s_id, SQLITE3_INTEGER);
            } else {
                $stmt = $db->prepare('UPDATE series SET last_enriched = datetime("now", "localtime") WHERE ID = :id');
                $stmt->bindValue(':id', $s_id, SQLITE3_INTEGER);
            }
            if (!$stmt->execute()) throw new Exception("Failed to finalize series $s_id");
            $processed_count++;
        }

        if (!$db->exec('COMMIT')) {
            throw new Exception("Failed to commit transaction: " . $db->lastErrorMsg());
        }

        $redirect_url = 'index.php?enrich_success=' . $processed_count;
        if ($single_id > 0) {
            $redirect_url .= '&expanded_id=' . $single_id;
        }
        header('Location: ' . $redirect_url);
        exit;

    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $error_message = $e->getMessage();
    }

} else {
    // Scan for changes
    foreach ($series_to_process as $series) {
        if (!$series || !$series['tvdb_ID']) continue;
        
        try {
            $tvdb_id = (int)$series['tvdb_ID'];
            $tvdb_episodes = $tvdb->getSeriesEpisodes($tvdb_id);
            $tvdb_info = $tvdb->getSeriesInfo($tvdb_id);
            
            // Get DB episodes and handle duplicates
            $stmt = $db->prepare('SELECT * FROM episodes WHERE serie_ID = :id ORDER BY seen DESC, ID ASC');
            $stmt->bindValue(':id', $series['ID'], SQLITE3_INTEGER);
            $res = $stmt->execute();
            
            $db_episodes = [];
            $episodes_to_delete = [];
            
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $key = $row['season_number'] . '_' . $row['episode_number'];
                if (!isset($db_episodes[$key])) {
                    $db_episodes[$key] = $row;
                } else {
                    // This is a duplicate. Since we ORDER BY seen DESC, 
                    // the first one we encounter for a key is the preferred one (seen=1 if exists).
                    $episodes_to_delete[] = $row['ID'];
                }
            }

            // Remove duplicates from DB immediately
            if (!empty($episodes_to_delete)) {
                $db->exec('DELETE FROM episodes WHERE ID IN (' . implode(',', $episodes_to_delete) . ')');
            }

            $changes = ['add' => [], 'update' => [], 'remove' => [], 'status' => null];
            $tvdb_keys = [];

            foreach ($tvdb_episodes as $ep) {
                $s = (int)$ep['seasonNumber'];
                $n = (int)$ep['number'];
                $k = $s . '_' . $n;
                $tvdb_keys[] = $k;
                $title = $ep['name'] ?: 'TBA';
                $aired = $ep['aired'] ?: '';
                $runtime = (int)($ep['runtime'] ?? 45);

                if (!isset($db_episodes[$k])) {
                    $changes['add'][] = ['season' => $s, 'number' => $n, 'title' => $title, 'aired' => $aired, 'runtime' => $runtime];
                } else {
                    $db_ep = $db_episodes[$k];
                    $db_title = htmlspecialchars_decode($db_ep['episode_name']);
                    if (($db_title !== $title && $title !== 'TBA') || ($db_ep['date_aired'] !== $aired && $aired !== '') || ($db_ep['runtime'] != $runtime)) {
                        $changes['update'][] = [
                            'id' => $db_ep['ID'], 'season' => $s, 'number' => $n, 
                            'old_title' => $db_title, 'new_title' => $title, 
                            'old_date' => $db_ep['date_aired'], 'new_date' => $aired,
                            'old_runtime' => (int)$db_ep['runtime'], 'new_runtime' => $runtime
                        ];
                    }
                }
            }

            foreach ($db_episodes as $k => $db_ep) {
                if (!in_array($k, $tvdb_keys)) {
                    $changes['remove'][] = ['id' => $db_ep['ID'], 'season' => $db_ep['season_number'], 'number' => $db_ep['episode_number'], 'title' => htmlspecialchars_decode($db_ep['episode_name'])];
                }
            }

            if ($tvdb_info && isset($tvdb_info['status']['name'])) {
                if (($series['status'] ?: 'Continuing') !== $tvdb_info['status']['name']) {
                    $changes['status'] = ['old' => $series['status'] ?: 'Continuing', 'new' => $tvdb_info['status']['name']];
                }
            }

            if (!empty($changes['add']) || !empty($changes['update']) || !empty($changes['remove']) || $changes['status']) {
                $all_changes[$series['ID']] = ['name' => $series['series_name'], 'tvdb_id' => $tvdb_id, 'data' => $changes];
            } else {
                // Update timestamp even if no changes
                $upd = $db->prepare('UPDATE series SET last_enriched = datetime("now", "localtime") WHERE ID = :id');
                $upd->bindValue(':id', $series['ID'], SQLITE3_INTEGER);
                $upd->execute();
            }
        } catch (Exception $e) {
            // Skip failed series
        }
    }

    // If single mode and no changes found, redirect back immediately
    if ($single_id > 0 && empty($all_changes)) {
        header('Location: index.php?expanded_id=' . $single_id);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enrichment Review</title>
    <style>
        :root {
            --bg: #121212;
            --surface: #1e1e1e;
            --primary: #4CAF50;
            --text: #eeeeee;
            --text-dim: #888888;
            --border: #333333;
            --add: #44ff44;
            --upd: #ffaa00;
            --rem: #ff4444;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); padding: 20px; margin: 0; }
        .container { max-width: 1000px; margin: 0 auto; background: var(--surface); padding: 30px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.5); }
        h1 { color: var(--primary); margin-bottom: 30px; }
        .series-block { border: 1px solid var(--border); border-radius: 8px; margin-bottom: 25px; overflow: hidden; background: rgba(255,255,255,0.02); }
        .series-header { background: #2a2a2a; padding: 15px 20px; font-weight: bold; font-size: 1.1em; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .series-content { padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.95em; }
        th, td { padding: 12px; border: 1px solid var(--border); text-align: left; }
        th { background: rgba(255,255,255,0.05); color: var(--text-dim); text-transform: uppercase; font-size: 0.8em; letter-spacing: 1px; }
        .add-row { color: var(--add); }
        .upd-row { color: var(--upd); }
        .rem-row { color: var(--rem); }
        .btn-container { margin-top: 40px; display: flex; gap: 15px; border-top: 1px solid var(--border); padding-top: 30px; }
        .btn { padding: 12px 35px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; font-size: 1.1em; transition: all 0.2s; }
        .btn-apply { background: var(--primary); color: white; }
        .btn-apply:hover { background: #45a049; transform: translateY(-2px); }
        .btn-cancel { background: #444; color: white; text-decoration: none; display: inline-block; padding: 12px 35px; border-radius: 6px; }
        .status-box { background: rgba(76, 175, 80, 0.1); border: 1px solid var(--primary); padding: 12px; border-radius: 6px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        .section-title { font-size: 0.85em; font-weight: bold; margin-top: 20px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border); padding-bottom: 5px; }
        .empty-state { text-align: center; padding: 80px 0; }
        .empty-state h2 { color: var(--text-dim); }
        input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary); }
    </style>
</head>
<body>
    <div class="container">
        <h1><?php echo $single_id ? "Series Enrichment" : "Bulk Enrichment Review"; ?></h1>

        <?php if (isset($error_message)): ?>
            <div style="background: rgba(255, 68, 68, 0.2); border: 1px solid #ff4444; padding: 15px; border-radius: 8px; margin-bottom: 25px; color: #ff8888;">
                <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($all_changes)): ?>
            <div class="empty-state">
                <h2>No changes detected!</h2>
                <p>Everything is currently in sync with TVDB.</p>
                <br>
                <a href="index.php" class="btn-cancel">Return to Manager</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <?php foreach ($all_changes as $s_id => $group): 
                    $data = $group['data'];
                ?>
                    <input type="hidden" name="tvdb_ids[<?php echo $s_id; ?>]" value="<?php echo $group['tvdb_id']; ?>">
                    <div class="series-block">
                        <div class="series-header" onclick="toggleBlock(<?php echo $s_id; ?>)">
                            <span><?php echo htmlspecialchars($group['name']); ?></span>
                            <input type="checkbox" checked onclick="event.stopPropagation(); toggleSeriesGroup(this, <?php echo $s_id; ?>)">
                        </div>
                        <div class="series-content" id="content-<?php echo $s_id; ?>">
                            
                            <?php if ($data['status']): ?>
                                <div class="status-box">
                                    <input type="checkbox" name="status_upd[<?php echo $s_id; ?>]" value="<?php echo $data['status']['new']; ?>" checked>
                                    <span>Status Change: <strong><?php echo $data['status']['old']; ?></strong> &rarr; <strong><?php echo $data['status']['new']; ?></strong></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($data['add'])): ?>
                                <div class="section-title">New Episodes</div>
                                <table>
                                    <thead><tr><th></th><th>Episode</th><th>Title</th><th>Air Date</th></tr></thead>
                                    <?php foreach ($data['add'] as $ep): ?>
                                        <tr class="add-row">
                                            <?php $json = json_encode($ep); ?>
                                            <td style="width: 30px;"><input type="checkbox" name="add[<?php echo $s_id; ?>][]" value='<?php echo htmlspecialchars($json, ENT_QUOTES); ?>' checked></td>
                                            <td>S<?php echo str_pad($ep['season'], 2, '0', STR_PAD_LEFT); ?>E<?php echo str_pad($ep['number'], 2, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo htmlspecialchars($ep['title']); ?></td>
                                            <td><?php echo $ep['aired']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>

                            <?php if (!empty($data['update'])): ?>
                                <div class="section-title">Updates</div>
                                <table>
                                    <thead><tr><th></th><th>Episode</th><th>Changes</th></tr></thead>
                                    <?php foreach ($data['update'] as $ep): ?>
                                        <tr class="upd-row">
                                            <?php $json = json_encode(['title' => $ep['new_title'], 'date' => $ep['new_date'], 'runtime' => $ep['new_runtime']]); ?>
                                            <td style="width: 30px;"><input type="checkbox" name="update[<?php echo $ep['id']; ?>]" value='<?php echo htmlspecialchars($json, ENT_QUOTES); ?>' checked></td>
                                            <td>S<?php echo str_pad($ep['season'], 2, '0', STR_PAD_LEFT); ?>E<?php echo str_pad($ep['number'], 2, '0', STR_PAD_LEFT); ?></td>
                                            <td>
                                                <?php if($ep['old_title'] !== $ep['new_title']) echo "Title: <span style='text-decoration:line-through;opacity:0.6'>".htmlspecialchars($ep['old_title'])."</span> &rarr; " . htmlspecialchars($ep['new_title']) . "<br>"; ?>
                                                <?php if($ep['old_date'] !== $ep['new_date']) echo "Date: " . ($ep['old_date'] ?: 'None') . " &rarr; " . $ep['new_date'] . "<br>"; ?>
                                                <?php if($ep['old_runtime'] !== $ep['new_runtime']) echo "Runtime: " . $ep['old_runtime'] . "m &rarr; " . $ep['new_runtime'] . "m"; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>

                            <?php if (!empty($data['remove'])): ?>
                                <div class="section-title">Removals</div>
                                <table>
                                    <thead><tr><th></th><th>Episode</th><th>Title</th></tr></thead>
                                    <?php foreach ($data['remove'] as $ep): ?>
                                        <tr class="rem-row">
                                            <td style="width: 30px;"><input type="checkbox" name="remove[]" value="<?php echo $ep['id']; ?>" checked></td>
                                            <td>S<?php echo str_pad($ep['season'], 2, '0', STR_PAD_LEFT); ?>E<?php echo str_pad($ep['number'], 2, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo htmlspecialchars($ep['title']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="btn-container">
                    <button type="submit" name="apply_changes" class="btn btn-apply">Apply Selected Changes</button>
                    <a href="index.php" class="btn-cancel">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function toggleBlock(sId) {
            const content = document.getElementById('content-' + sId);
            content.style.display = content.style.display === 'none' ? 'block' : 'none';
        }
        function toggleSeriesGroup(master, sId) {
            const container = document.getElementById('content-' + sId);
            const checkboxes = container.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = master.checked);
        }
    </script>
</body>
</html>