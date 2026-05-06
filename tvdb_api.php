<?php
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $_ENV[$name] = $value;
        putenv("$name=$value");
    }
}

loadEnv(__DIR__ . '/.env');

class TVDB {
    private $apiKey;
    private $token;
    private $apiUrl = 'https://api4.thetvdb.com/v4';

    public function __construct() {
        $this->apiKey = getenv('TVDB_API_KEY');
    }

    private function authenticate() {
        if (!$this->apiKey) return false;
        
        $ch = curl_init($this->apiUrl . '/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['apikey' => $this->apiKey]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $data = json_decode($response, true);
        curl_close($ch);

        if ($httpCode === 200 && isset($data['data']['token'])) {
            $this->token = $data['data']['token'];
            return true;
        }
        return false;
    }

    public function getSeriesInfo($tvdbId) {
        if (!$tvdbId) return null;
        if (!$this->token) {
            if (!$this->authenticate()) return null;
        }

        $ch = curl_init($this->apiUrl . '/series/' . $tvdbId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (isset($data['data'])) {
            return $data['data'];
        }
        return null;
    }

    public function getSeriesArtwork($tvdbId) {
        if (!$tvdbId) return null;
        if (!$this->token) {
            if (!$this->authenticate()) return null;
        }

        $ch = curl_init($this->apiUrl . '/series/' . $tvdbId . '/extended');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (isset($data['data']['artworks'])) {
            // Sort by score if available, or just look for poster
            foreach ($data['data']['artworks'] as $artwork) {
                // Type 2 is Poster
                if ($artwork['type'] == 2 && !empty($artwork['image'])) {
                    return $artwork['image'];
                }
            }
            // Fallback to type 3 (Background/Fanart)
            foreach ($data['data']['artworks'] as $artwork) {
                if ($artwork['type'] == 3 && !empty($artwork['image'])) {
                    return $artwork['image'];
                }
            }
        }
        return null;
    }

    public function getSeriesEpisodes($tvdbId) {
        if (!$tvdbId) return [];
        if (!$this->token) {
            if (!$this->authenticate()) return [];
        }

        $allEpisodes = [];
        $page = 0;
        
        do {
            $ch = curl_init($this->apiUrl . '/series/' . $tvdbId . '/episodes/default?page=' . $page);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $data = json_decode($response, true);
            curl_close($ch);

            if (isset($data['data']['episodes'])) {
                $allEpisodes = array_merge($allEpisodes, $data['data']['episodes']);
                // Check if there's more pages
                if (count($data['data']['episodes']) < 100) { // TVDB usually pages at 100
                    break;
                }
                $page++;
            } else {
                break;
            }
        } while ($page < 20); // Safety limit

        return $allEpisodes;
    }
}

// Handle AJAX request - only if specifically requested via action parameter
if (isset($_GET['action']) && $_GET['action'] === 'get_artwork' && isset($_GET['tvdb_id'])) {
    header('Content-Type: application/json');
    $tvdbId = (int)$_GET['tvdb_id'];
    $seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : 0;
    
    $tvdb = new TVDB();
    $artwork = $tvdb->getSeriesArtwork($tvdbId);
    
    // Cache it in DB if we have a series_id
    if ($artwork && $seriesId) {
        try {
            $db = new SQLite3('series_v3.db', SQLITE3_OPEN_READWRITE);
            $stmt = $db->prepare('UPDATE series SET artwork_url = :url WHERE ID = :id AND (artwork_url IS NULL OR artwork_url = "")');
            $stmt->bindValue(':url', $artwork, SQLITE3_TEXT);
            $stmt->bindValue(':id', $seriesId, SQLITE3_INTEGER);
            $stmt->execute();
        } catch (Exception $e) {
            // Silently fail DB update
        }
    }
    
    echo json_encode(['artwork' => $artwork]);
    exit;
}
?>
