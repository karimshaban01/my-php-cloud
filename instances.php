<?php
/* ----------------------------------------------------------------
 *  instances.php – Stellar Cloud Console (LXC view backed by Proxmox)
 * ----------------------------------------------------------------
 *  • Logs in (or uses API token)                           *
 *  • Grabs every container on a node                       *
 *  • Gets status/current for IP & state                    *
 *  • Renders counts + rows into your gorgeous HTML         *
 * -------------------------------------------------------------- */

/* ===== CONFIG ===== */
$PVE_HOST      = '10.42.0.17';
$PVE_NODE      = 'k-tronics';
$VERIFY_SSL    = false;                   // set TRUE or CA bundle in prod

// Option A: API token (recommended)
$PVE_API_TOKEN = '';                      // "user@pve!token=ABCDEF=0123456789"

// Option B: fallback username / password
$PVE_USER      = 'root@pam';
$PVE_PASSWORD  = 'Karimshaban@01';

/* ===== HELPER ===== */
$BASE = "https://{$PVE_HOST}:8006/api2/json";

function pve_request(
    string $method,
    string $url,
    array  $headers = [],
    array  $fields  = null,
    bool   $verify  = true
) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_SSL_VERIFYPEER => $verify ? 1 : 0,
    ]);
    if ($fields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new RuntimeException('cURL: '.curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) throw new RuntimeException("HTTP $code: $resp");
    return json_decode($resp, true);
}

/* ===== AUTH ===== */
try {
    if ($PVE_API_TOKEN !== '') {
        $ticketHdr = "Authorization: PVEAPIToken=$PVE_API_TOKEN";
        $csrfHdr   = [];
    } else {
        $login = pve_request(
            'POST',
            "$BASE/access/ticket",
            ['Content-Type: application/x-www-form-urlencoded'],
            ['username'=>$PVE_USER,'password'=>$PVE_PASSWORD],
            $VERIFY_SSL
        )['data'];
        $ticketHdr = "Cookie: PVEAuthCookie={$login['ticket']}";
        $csrfHdr   = ["CSRFPreventionToken: {$login['CSRFPreventionToken']}"];
    }

    /* ===== GET CONTAINERS ===== */
    $ctList = pve_request(
        'GET',
        "$BASE/nodes/$PVE_NODE/lxc",
        [$ticketHdr],
        null,
        $VERIFY_SSL
    )['data'];

    $instances = [];
    foreach ($ctList as $ct) {
        $vmid   = $ct['vmid'];
        $name   = $ct['name'];
        $status = $ct['status'];              // running, stopped …

        // Detailed status for IP
        $detail = pve_request(
            'GET',
            "$BASE/nodes/$PVE_NODE/lxc/$vmid/interfaces",
            //"$BASE/nodes/$PVE_NODE/lxc/$vmid/status/current",
            [$ticketHdr],
            null,
            $VERIFY_SSL
        )['data'];
        

        $ips = [];
        if (isset($detail)) {
            //echo $detail;
            foreach ($detail as $iface) {
                
                foreach ($iface['ip-addresses']??[] as $ipObj) {
                    if (filter_var($ipObj['ip-address'], FILTER_VALIDATE_IP))
                        $ips[] = $ipObj['ip-address'];
                    echo $ips[2];
                }
            }
        }
        if (isset($detail['ip']) && filter_var($detail['ip'], FILTER_VALIDATE_IP))
            $ips[] = $detail['ip'];

        $instances[] = [
            'status'     => $status,          // for colour dots
            'state'      => $status,          // same for badge
            'name'       => $name ?: "ct$vmid",
            'id'         => $vmid,
            'type'       => sprintf('%dC %dMB',
                                    $detail['cpu'] ?? $ct['maxcpu'] ?? 1,
                                    round(($detail['mem'] ?? $ct['maxmem'] ?? 512*1024*1024)/1048576)
                                   ),
            'zone'       => $PVE_NODE,
            'public_ip'  => $ips[2] ?? '—',
            'private_ip' => $ips[0] ?? $ips[0] ?? '—',
        ];
    }

    /* Counts for header */
    $total    = count($instances);
    $running  = count(array_filter($instances, fn($i)=>$i['state']==='running'));
    $stopped  = count(array_filter($instances, fn($i)=>$i['state']==='stopped'));
    $pending  = count(array_filter($instances, fn($i)=>$i['state']==='paused'||$i['state']==='pending'));
} catch (Throwable $e) {
    die("<p style='color:red;padding:2rem;font-family:monospace'>Proxmox API error:<br>"
        .htmlspecialchars($e->getMessage())."</p>");
}
?>


 <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EC2 Dashboard - Stellar Cloud Console</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0a0a1a 0%, #1a1a2e 30%, #16213e 70%, #0f3460 100%);
            color: #e0e6ff;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated stars background */
        .stars {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .star {
            position: absolute;
            background: #fff;
            border-radius: 50%;
            animation: twinkle 3s infinite;
        }

        .star:nth-child(odd) {
            animation-delay: 1.5s;
        }

        @keyframes twinkle {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.2); }
        }

        /* Header */
        .header {
            background: rgba(10, 10, 26, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(64, 224, 255, 0.3);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .top-bar {
            background: rgba(0, 0, 0, 0.2);
            padding: 0.5rem 2rem;
            border-bottom: 1px solid rgba(64, 224, 255, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
        }

        .breadcrumb {
            color: #7a8cff;
        }

        .breadcrumb a {
            color: #40e0ff;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #a0b3ff;
        }

        .main-nav {
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.5rem;
            font-weight: bold;
            color: #40e0ff;
        }

        .logo::before {
            content: "🌌";
            font-size: 1.8rem;
        }

        .service-nav {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .service-nav a {
            color: #a0b3ff;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .service-nav a:hover,
        .service-nav a.active {
            color: #40e0ff;
            background: rgba(64, 224, 255, 0.1);
        }

        .global-actions {
            display: flex;
            gap: 1rem;
        }

        /* Sidebar */
        .layout {
            display: flex;
            position: relative;
            z-index: 10;
        }

        .sidebar {
            width: 260px;
            background: rgba(10, 10, 26, 0.8);
            backdrop-filter: blur(15px);
            border-right: 1px solid rgba(64, 224, 255, 0.2);
            padding: 1.5rem 0;
            height: calc(100vh - 120px);
            overflow-y: auto;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-title {
            padding: 0 1.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #40e0ff;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu li {
            margin-bottom: 0.2rem;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.7rem 1.5rem;
            color: #a0b3ff;
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(64, 224, 255, 0.1);
            color: #40e0ff;
            border-right: 3px solid #40e0ff;
        }

        .sidebar-menu .icon {
            width: 16px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            max-width: calc(100% - 260px);
        }

        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .page-title-section {
            flex: 1;
        }

        .page-title {
            font-size: 2.2rem;
            font-weight: 300;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #40e0ff, #ffffff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            color: #a0b3ff;
            font-size: 1rem;
            margin-bottom: 1rem;
        }

        .resource-summary {
            display: flex;
            gap: 2rem;
            font-size: 0.9rem;
            color: #7a8cff;
        }

        .resource-summary span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .primary-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.5rem;
            border: 1px solid rgba(64, 224, 255, 0.5);
            background: rgba(64, 224, 255, 0.1);
            color: #40e0ff;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn:hover {
            background: rgba(64, 224, 255, 0.2);
            border-color: #40e0ff;
            box-shadow: 0 0 15px rgba(64, 224, 255, 0.3);
        }

        .btn-primary {
            background: linear-gradient(135deg, #40e0ff, #0066cc);
            border-color: #40e0ff;
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #66ebff, #0080ff);
            box-shadow: 0 0 20px rgba(64, 224, 255, 0.5);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.3);
            color: #e0e6ff;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.5);
        }

        /* Filters and Controls */
        .controls-section {
            margin-bottom: 2rem;
        }

        .filters-bar {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .search-input {
            padding: 0.7rem 1rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(64, 224, 255, 0.3);
            border-radius: 6px;
            color: #e0e6ff;
            font-size: 0.9rem;
            width: 300px;
        }

        .search-input::placeholder {
            color: #7a8cff;
        }

        .search-input:focus {
            outline: none;
            border-color: #40e0ff;
            box-shadow: 0 0 10px rgba(64, 224, 255, 0.3);
        }

        .filter-dropdown {
            padding: 0.7rem 1rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(64, 224, 255, 0.3);
            border-radius: 6px;
            color: #e0e6ff;
            cursor: pointer;
            min-width: 120px;
        }

        .view-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-left: auto;
        }

        .view-btn {
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(64, 224, 255, 0.3);
            color: #40e0ff;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .view-btn.active,
        .view-btn:hover {
            background: rgba(64, 224, 255, 0.2);
            border-color: #40e0ff;
        }

        .bulk-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            padding: 1rem;
            background: rgba(64, 224, 255, 0.1);
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid rgba(64, 224, 255, 0.2);
        }

        .bulk-actions.hidden {
            display: none;
        }

        .selected-count {
            color: #40e0ff;
            font-weight: 600;
        }

        /* Instances Table */
        .instances-container {
            background: rgba(10, 10, 26, 0.8);
            backdrop-filter: blur(15px);
            border-radius: 12px;
            border: 1px solid rgba(64, 224, 255, 0.2);
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .table-header {
            background: rgba(64, 224, 255, 0.1);
            padding: 1rem;
            border-bottom: 1px solid rgba(64, 224, 255, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-weight: 600;
            color: #40e0ff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-actions {
            display: flex;
            gap: 0.5rem;
        }

        .table-header-row {
            display: grid;
            grid-template-columns: 40px 50px 2fr 1fr 1fr 1fr 1fr 1fr 120px;
            gap: 1rem;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid rgba(64, 224, 255, 0.1);
            font-size: 0.85rem;
            font-weight: 600;
            color: #a0b3ff;
        }

        .instance-row {
            display: grid;
            grid-template-columns: 40px 50px 2fr 1fr 1fr 1fr 1fr 1fr 120px;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid rgba(64, 224, 255, 0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            align-items: center;
        }

        .instance-row:hover {
            background: rgba(64, 224, 255, 0.05);
        }

        .instance-row.selected {
            background: rgba(64, 224, 255, 0.1);
            border-left: 4px solid #40e0ff;
        }

        .instance-row:last-child {
            border-bottom: none;
        }

        .checkbox-cell {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .checkbox {
            width: 16px;
            height: 16px;
            border: 2px solid #40e0ff;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .checkbox.checked {
            background: #40e0ff;
            border-color: #40e0ff;
        }

        .checkbox.checked::after {
            content: "✓";
            color: white;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }

        .instance-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            animation: pulse 2s infinite;
            margin: 0 auto;
        }

        .status-running {
            background: #00ff88;
            box-shadow: 0 0 10px rgba(0, 255, 136, 0.5);
        }

        .status-stopped {
            background: #ff4444;
            box-shadow: 0 0 10px rgba(255, 68, 68, 0.5);
        }

        .status-pending {
            background: #ffaa00;
            box-shadow: 0 0 10px rgba(255, 170, 0, 0.5);
        }

        .status-terminated {
            background: #666;
            animation: none;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .instance-name {
            font-weight: 600;
            color: #e0e6ff;
            margin-bottom: 0.2rem;
        }

        .instance-id {
            font-size: 0.8rem;
            color: #7a8cff;
            font-family: monospace;
        }

        .instance-type {
            padding: 0.2rem 0.6rem;
            background: rgba(64, 224, 255, 0.2);
            border-radius: 12px;
            font-size: 0.8rem;
            color: #40e0ff;
            font-weight: 500;
            display: inline-block;
        }

        .instance-state {
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .state-running {
            background: rgba(0, 255, 136, 0.2);
            color: #00ff88;
        }

        .state-stopped {
            background: rgba(255, 68, 68, 0.2);
            color: #ff6666;
        }

        .state-pending {
            background: rgba(255, 170, 0, 0.2);
            color: #ffaa00;
        }

        .state-terminated {
            background: rgba(102, 102, 102, 0.2);
            color: #999;
        }

        .instance-actions {
            display: flex;
            gap: 0.3rem;
        }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: 1px solid rgba(64, 224, 255, 0.3);
            background: rgba(64, 224, 255, 0.1);
            color: #40e0ff;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.75rem;
        }

        .action-btn:hover {
            background: rgba(64, 224, 255, 0.2);
            box-shadow: 0 0 8px rgba(64, 224, 255, 0.3);
        }

        .action-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Footer */
        .table-footer {
            padding: 1rem;
            background: rgba(0, 0, 0, 0.2);
            border-top: 1px solid rgba(64, 224, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pagination {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .pagination button {
            padding: 0.5rem 0.8rem;
            background: rgba(64, 224, 255, 0.1);
            border: 1px solid rgba(64, 224, 255, 0.3);
            color: #40e0ff;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .pagination button:hover:not(:disabled) {
            background: rgba(64, 224, 255, 0.2);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination .current {
            background: #40e0ff;
            color: #000;
        }

        .items-per-page {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: rgba(10, 10, 26, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 12px;
            border: 1px solid rgba(64, 224, 255, 0.3);
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.3rem;
            color: #40e0ff;
        }

        .modal-close {
            background: none;
            border: none;
            color: #a0b3ff;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: rgba(64, 224, 255, 0.1);
            color: #40e0ff;
        }

        .modal-body {
            margin-bottom: 2rem;
            color: #e0e6ff;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 220px;
            }
            
            .main-content {
                max-width: calc(100% - 220px);
            }
        }

        @media (max-width: 768px) {
            .layout {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                border-right: none;
                border-bottom: 1px solid rgba(64, 224, 255, 0.2);
            }
            
            .main-content {
                max-width: 100%;
                padding: 1rem;
            }
            
            .table-header-row,
            .instance-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-input {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Animated stars -->
    <div class="stars" id="stars"></div>

    <!-- Header -->
    <header class="header">
        <div class="top-bar">
            <div class="breadcrumb">
                <a href="#">Stellar Cloud</a> > <a href="#">EC2</a> > Instances
            </div>
            <div class="user-menu">
                <span>us-nebula-1</span>
                <span>|</span>
                <span>Administrator</span>
                <span>|</span>
                <span>Support</span>
            </div>
        </div>
        <div class="main-nav">
            <div class="logo">
                Stellar Cloud Console
            </div>
            <nav class="service-nav">
                <a href="#" class="active">EC2</a>
                <a href="#">VPC</a>
                <a href="#">S3</a>
                <a href="#">RDS</a>
                <a href="#">Lambda</a>
                <a href="#">CloudWatch</a>
            </nav>
            <div class="global-actions">
                <button class="btn btn-secondary">🔔</button>
                <button class="btn btn-secondary">⚙️</button>
            </div>
        </div>
    </header>

    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-section">
                <div class="sidebar-title">Instances</div>
                <ul class="sidebar-menu">
                    <li><a href="#" class="active"><span class="icon">🖥️</span> Instances</a></li>
                    <li><a href="#"><span class="icon">📊</span> Instance Types</a></li>
                    <li><a href="#"><span class="icon">🚀</span> Launch Templates</a></li>
                    <li><a href="#"><span class="icon">📦</span> Spot Requests</a></li>
                    <li><a href="#"><span class="icon">💾</span> Reserved Instances</a></li>
                    <li><a href="#"><span class="icon">🔧</span> Dedicated Hosts</a></li>
                    <li><a href="#"><span class="icon">⚡</span> Scheduled Instances</a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-title">Images</div>
                <ul class="sidebar-menu">
                    <li><a href="#"><span class="icon">💿</span> AMIs</a></li>
                    <li><a href="#"><span class="icon">📸</span> Snapshots</a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-title">Elastic Block Store</div>
                <ul class="sidebar-menu">
                    <li><a href="#"><span class="icon">💽</span> Volumes</a></li>
                    <li><a href="#"><span class="icon">📸</span> Snapshots</a></li>
                    <li><a href="#"><span class="icon">🔑</span> Lifecycle Manager</a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-title">Network & Security</div>
                <ul class="sidebar-menu">
                    <li><a href="#"><span class="icon">🔐</span> Security Groups</a></li>
                    <li><a href="#"><span class="icon">🗝️</span> Key Pairs</a></li>
                    <li><a href="#"><span class="icon">🌐</span> Elastic IPs</a></li>
                    <li><a href="#"><span class="icon">🔗</span> Placement Groups</a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-title">Load Balancing</div>
                <ul class="sidebar-menu">
                    <li><a href="#"><span class="icon">⚖️</span> Load Balancers</a></li>
                    <li><a href="#"><span class="icon">🎯</span> Target Groups</a></li>
                </ul>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <div class="page-title-section">
                    <h1 class="page-title">Instances</h1>
                    <p class="page-subtitle">Virtual servers in the cosmic cloud</p>
                    <div class="resource-summary">
                        <span>🖥️ 12 Instances</span>
                        <span>🟢 8 Running</span>
                        <span>🔴 2 Stopped</span>
                        <span>🟡 2 Pending</span>
                    </div>
                </div>
                <div class="primary-actions">
                    <button class="btn btn-primary">🚀 Launch Instance</button>
                    <button class="btn btn-secondary">📊 Connect</button>
                    <button class="btn btn-secondary">📁 Import</button>
                </div>
            </div>

            <div class="controls-section">
                <div class="filters-bar">
                    <input type="text" class="search-input" placeholder="Search instances by name, ID, type, or tag...">
                    <select class="filter-dropdown">
                        <option>All states</option>
                        <option>Running</option>
                        <option>Stopped</option>
                        <option>Pending</option>
                        <option>Terminated</option>
                    </select>
                    <select class="filter-dropdown">
                        <option>All types</option>
                        <option>t3.micro</option>
                        <option>t3.small</option>
                        <option>t3.medium</option>
                        <option>c5.large</option>
                        <option>r5.large</option>
                    </select>
                    <select class="filter-dropdown">
                        <option>All zones</option>
                        <option>us-nebula-1a</option>
                        <option>us-nebula-1b</option>
                        <option>eu-galaxy-1a</option>
                        <option>ap-cosmos-1a</option>
                    </select>
                    <button class="btn btn-secondary">🔄 Refresh</button>
                    <div class="view-controls">
                        <button class="view-btn active" title="List View">☰</button>
                        <button class="view-btn" title="Grid View">⊞</button>
                        <button class="view-btn" title="Card View">▦</button>
                    </div>
                </div>
                
                <div class="bulk-actions hidden" id="bulkActions">
                    <span class="selected-count">3 instances selected</span>
                    <button class="btn btn-secondary">▶️ Start</button>
                    <button class="btn btn-secondary">⏸️ Stop</button>
                    <button class="btn btn-secondary">🔄 Reboot</button>
                    <button class="btn btn-secondary">🗑️ Terminate</button>
                    <button class="btn btn-secondary">🏷️ Manage Tags</button>
                </div>
            </div>

            <div class="instances-container">
                <div class="table-header">
                    <div class="table-title">
                        <span>EC2 Instances</span>
                        <span>(12)</span>
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-secondary">⚙️ Manage Columns</button>
                        <button class="btn btn-secondary">📤 Export</button>
                    </div>
                </div>

                <div class="table-header-row">
                    <div class="checkbox-cell">
                        <div class="checkbox" id="selectAll"></div>
                    </div>
                    <div>Status</div>
                    <div>Name / Instance ID</div>
                    <div>Instance Type</div>
                    <div>State</div>
                    <div>Availability Zone</div>
                    <div>Public IPv4 Address</div>
                    <div>Private IPv4 Address</div>
                    <div>Actions</div>
                </div>

                <?php

                foreach ($instances as $instance) {
                    $statusClass = 'status-' . $instance['status'];
                    $stateClass = 'state-' . $instance['state'];
                    echo '<div class="instance-row">';
                    echo '<div class="checkbox-cell"><div class="checkbox"></div></div>';
                    echo '<div><div class="instance-status ' . $statusClass . '"></div></div>';
                    echo '<div>';
                    echo '<div class="instance-name">' . htmlspecialchars($instance['name']) . '</div>';
                    echo '<div class="instance-id">' . htmlspecialchars($instance['id']) . '</div>';
                    echo '</div>';
                    echo '<div><span class="instance-type">' . htmlspecialchars($instance['type']) . '</span></div>';
                    echo '<div><span class="instance-state ' . $stateClass . '">' . htmlspecialchars($instance['state']) . '</span></div>';
                    echo '<div>' . htmlspecialchars($instance['zone']) . '</div>';
                    echo '<div>' . htmlspecialchars($instance['public_ip']) . '</div>';
                    echo '<div>' . htmlspecialchars($instance['private_ip']) . '</div>';
                    echo '<div class="instance-actions">';
                    echo '<button class="action-btn">▶️ Start</button>';
                    echo '<button class="action-btn">⏸️ Stop</button>';
                    echo '<button class="action-btn">🔄 Reboot</button>';
                    echo '<button class="action-btn">🗑️ Terminate</button>';
                    echo '</div>';
                    echo '</div>';
                }
                ?>