<?php
// deploy.php - Galactic Container Deployment System
// Configuration
$pve_host     = "10.42.0.17";
$pve_node     = "k-tronics";
$pve_user     = "root@pam";
$pve_password = "Karimshaban@01";
$verify_ssl   = false;

function pve_login($host, $user, $pass, $verify_ssl) {
    $url = "https://$host:8006/api2/json/access/ticket";
    $data = http_build_query([
        'username' => $user,
        'password' => $pass
    ]);
    $opts = ['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded",
        'content' => $data,
        'ignore_errors' => true
    ]];
    if (!$verify_ssl) {
        $opts['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
    }
    $context = stream_context_create($opts);
    $result = file_get_contents($url, false, $context);
    $response = json_decode($result, true);
    return $response['data'] ?? null;
}

function create_lxc($host, $node, $ticket, $csrf, $params, $verify_ssl) {
    $url = "https://$host:8006/api2/json/nodes/$node/lxc";
    $opts = ['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n" .
                    "Cookie: PVEAuthCookie=$ticket\r\n" .
                    "CSRFPreventionToken: $csrf",
        'content' => http_build_query($params),
        'ignore_errors' => true
    ]];
    if (!$verify_ssl) {
        $opts['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
    }
    $context = stream_context_create($opts);
    $result = file_get_contents($url, false, $context);
    return json_decode($result, true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🌌 Galactic Instance Launch</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Helvetica Neue', Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #0a0a1e 0%, #1a1a3e 25%, #2d4a6b 50%, #1a1a3e 75%, #0a0a1e 100%);
            background-attachment: fixed;
            color: #e0e6ed;
            line-height: 1.4;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Space background with Earth */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1920 1080"><defs><radialGradient id="earth" cx="40%" cy="30%"><stop offset="0%" stop-color="%2364b5f6"/><stop offset="50%" stop-color="%232196f3"/><stop offset="100%" stop-color="%231976d2"/></radialGradient></defs><rect width="100%" height="100%" fill="%23000"/><circle cx="300" cy="200" r="120" fill="url(%23earth)" opacity="0.8"/></svg>');
            background-size: cover;
            z-index: -2;
        }

        /* Animated stars */
        .stars {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }

        .star {
            position: absolute;
            background: #ffffff;
            border-radius: 50%;
            animation: twinkle 3s infinite;
        }

        @keyframes twinkle {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.2); }
        }

        .header {
            background: rgba(13, 25, 38, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(100, 181, 246, 0.3);
            box-shadow: 0 2px 20px rgba(0,0,0,0.3);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            padding: 12px 20px;
        }

        .logo {
            color: #64b5f6;
            font-size: 20px;
            font-weight: bold;
            margin-right: 30px;
            text-shadow: 0 0 10px rgba(100, 181, 246, 0.5);
        }

        .nav-links {
            display: flex;
            gap: 20px;
        }

        .nav-links a {
            color: #b0bec5;
            text-decoration: none;
            font-size: 14px;
            padding: 8px 12px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .nav-links a:hover {
            background: rgba(100, 181, 246, 0.1);
            color: #64b5f6;
            box-shadow: 0 0 10px rgba(100, 181, 246, 0.3);
        }

        .nav-links a.active {
            color: #64b5f6;
            background: rgba(100, 181, 246, 0.1);
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .breadcrumb {
            font-size: 14px;
            color: #90caf9;
            margin-bottom: 20px;
        }

        .breadcrumb a {
            color: #64b5f6;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb a:hover {
            color: #90caf9;
            text-shadow: 0 0 5px rgba(100, 181, 246, 0.5);
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 400;
            color: #e0e6ed;
            margin-bottom: 8px;
            text-shadow: 0 0 10px rgba(224, 230, 237, 0.3);
        }

        .page-description {
            color: #b0bec5;
            font-size: 14px;
        }

        .content-wrapper {
            display: flex;
            gap: 20px;
        }

        .main-content {
            flex: 1;
        }

        .sidebar {
            width: 280px;
        }

        .card {
            background: rgba(25, 39, 52, 0.9);
            border: 1px solid rgba(100, 181, 246, 0.2);
            border-radius: 12px;
            margin-bottom: 20px;
            overflow: hidden;
            backdrop-filter: blur(10px);
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            position: relative;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(100, 181, 246, 0.05), transparent);
            animation: scan 4s infinite;
        }

        @keyframes scan {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        .card-header {
            background: rgba(13, 25, 38, 0.8);
            padding: 16px 20px;
            border-bottom: 1px solid rgba(100, 181, 246, 0.2);
            font-weight: 600;
            color: #90caf9;
            position: relative;
            z-index: 1;
        }

        .card-body {
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #e0e6ed;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #64b5f6;
            text-shadow: 0 0 10px rgba(100, 181, 246, 0.3);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #90caf9;
            margin-bottom: 6px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid rgba(100, 181, 246, 0.3);
            border-radius: 8px;
            font-size: 14px;
            background: rgba(13, 25, 38, 0.7);
            color: #e0e6ed;
            transition: all 0.3s ease;
            outline: none;
        }

        .form-input:focus {
            border-color: #64b5f6;
            box-shadow: 0 0 20px rgba(100, 181, 246, 0.3);
            background: rgba(13, 25, 38, 0.9);
        }

        .form-help {
            font-size: 12px;
            color: #78909c;
            margin-top: 4px;
        }

        .instance-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .instance-type-card {
            border: 2px solid rgba(100, 181, 246, 0.3);
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(13, 25, 38, 0.5);
        }

        .instance-type-card:hover {
            border-color: #64b5f6;
            box-shadow: 0 0 20px rgba(100, 181, 246, 0.3);
            transform: translateY(-2px);
        }

        .instance-type-card.selected {
            border-color: #64b5f6;
            background: rgba(100, 181, 246, 0.1);
            box-shadow: 0 0 20px rgba(100, 181, 246, 0.4);
        }

        .instance-type-name {
            font-weight: 600;
            color: #e0e6ed;
            margin-bottom: 4px;
        }

        .instance-type-specs {
            font-size: 12px;
            color: #90caf9;
        }

        .launch-button {
            background: linear-gradient(45deg, #1976d2, #2196f3, #42a5f5);
            color: white;
            border: none;
            padding: 15px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.4);
        }

        .launch-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .launch-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.6);
        }

        .launch-button:hover::before {
            left: 100%;
        }

        .launch-button:disabled {
            background: #455a64;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid;
            position: relative;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.2);
            border-color: rgba(76, 175, 80, 0.5);
            color: #a5d6a7;
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.2);
            border-color: rgba(244, 67, 54, 0.5);
            color: #ef9a9a;
        }

        .info-box {
            background: rgba(100, 181, 246, 0.1);
            border: 1px solid rgba(100, 181, 246, 0.3);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .info-box-title {
            font-weight: 600;
            color: #64b5f6;
            margin-bottom: 8px;
        }

        .info-box-content {
            font-size: 14px;
            color: #90caf9;
        }

        .price-info {
            background: rgba(25, 39, 52, 0.8);
            border: 1px solid rgba(100, 181, 246, 0.2);
            border-radius: 8px;
            padding: 16px;
            margin-top: 20px;
        }

        .price-title {
            font-weight: 600;
            color: #e0e6ed;
            margin-bottom: 10px;
        }

        .price-details {
            font-size: 14px;
            color: #90caf9;
        }

        /* Floating orbs */
        .floating-orb {
            position: fixed;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(100, 181, 246, 0.4), rgba(33, 150, 243, 0.2));
            z-index: -1;
            animation: float 8s ease-in-out infinite;
        }

        .orb-1 {
            width: 200px;
            height: 200px;
            top: 20%;
            right: 10%;
            animation-delay: 0s;
        }

        .orb-2 {
            width: 150px;
            height: 150px;
            bottom: 20%;
            left: 5%;
            animation-delay: -3s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(180deg); }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                order: -1;
            }
            
            .instance-type-grid {
                grid-template-columns: 1fr;
            }

            .floating-orb {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="stars"></div>
    <div class="floating-orb orb-1"></div>
    <div class="floating-orb orb-2"></div>

    <header class="header">
        <div class="header-content">
            <div class="logo">🌌 GALACTIC CLOUD</div>
            <nav class="nav-links">
                <a href="#" class="active">Instances</a>
                <a href="#">Networks</a>
                <a href="#">Storage</a>
                <a href="#">Databases</a>
                <a href="#">Functions</a>
            </nav>
        </div>
    </header>

    <div class="main-container">
        <div class="breadcrumb">
            <a href="#">🚀 Instances</a> > <a href="#">Deployment Bay</a> > Launch Instance
        </div>

        <div class="page-header">
            <h1 class="page-title">🌟 Launch Galactic Instance</h1>
            <p class="page-description">Deploy a virtual spacecraft in the cosmic cloud. Configure your instance with stellar computing power across the galaxy's most advanced infrastructure.</p>
        </div>

        <div class="content-wrapper">
            <div class="main-content">
                <?php
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $hostname = htmlspecialchars($_POST['hostname']);
                    $password = htmlspecialchars($_POST['password']);
                    
                    // Login to Proxmox
                    $login = pve_login($pve_host, $pve_user, $pve_password, $verify_ssl);
                    if ($login) {
                        $ticket = $login['ticket'];
                        $csrf   = $login['CSRFPreventionToken'];
                        
                        // Random VMID for free tier
                        $vmid = rand(100, 199);
                        $params = [
                            'vmid' => $vmid,
                            'hostname' => $hostname,
                            'ostemplate' => 'local:vztmpl/ubuntu-20.04-standard_20.04-1_amd64.tar.gz',
                            'memory' => 512,
                            'cores' => 1,
                            'rootfs' => 'local-lvm:4',
                            'net0' => 'name=eth0,bridge=vmbr0,ip=dhcp',
                            'password' => $password,
                            'storage' => 'local-lvm',
                            'start' => 1
                        ];
                        
                        $response = create_lxc($pve_host, $pve_node, $ticket, $csrf, $params, $verify_ssl);
                        if (isset($response['data'])) {
                            echo "<div class='alert alert-success'>
                                <strong>🚀 Launch Successful!</strong> Your galactic instance is initializing.
                                <br><strong>🆔 Instance ID:</strong> gx-" . substr(md5($response['data']), 0, 8) . "
                                <br><strong>📡 Mission ID:</strong> {$response['data']}
                                <br><strong>🔢 VMID:</strong> $vmid
                            </div>";
                        } else {
                            echo "<div class='alert alert-error'><strong>❌ Launch Sequence Failed:</strong> ".htmlspecialchars(json_encode($response))."</div>";
                        }
                    } else {
                        echo "<div class='alert alert-error'><strong>🔒 Authentication Failed:</strong> Unable to connect to mission control.</div>";
                    }
                }
                ?>

                <form method="post">
                    <div class="card">
                        <div class="card-header">🏷️ Spacecraft Identification</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">Instance Name</label>
                                <input type="text" name="hostname" class="form-input" placeholder="stellar-server-01" required>
                                <div class="form-help">A unique identifier for your cosmic instance</div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">🖥️ Operating System (Galactic Images)</div>
                        <div class="card-body">
                            <div class="info-box">
                                <div class="info-box-title">🐧 Ubuntu Server 20.04 LTS - Stellar Edition</div>
                                <div class="info-box-content">
                                    Free tier eligible • 64-bit (x86) • Quantum Storage Compatible
                                    <br>Optimized for interstellar applications with enhanced cosmic stability.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">⚡ Instance Configuration</div>
                        <div class="card-body">
                            <div class="instance-type-grid">
                                <div class="instance-type-card selected">
                                    <div class="instance-type-name">🌌 Nebula.micro</div>
                                    <div class="instance-type-specs">1 vCPU Core, 512 MB Memory</div>
                                    <div class="instance-type-specs">Free tier • Perfect for small satellites</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">🔐 Access Configuration</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">Root Access Key</label>
                                <input type="password" name="password" class="form-input" placeholder="Enter secure cosmic password" required>
                                <div class="form-help">This will be your master access key to the instance</div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">🌐 Network Configuration</div>
                        <div class="card-body">
                            <div class="info-box">
                                <div class="info-box-title">🛰️ Default Cosmic Network</div>
                                <div class="info-box-content">
                                    Your instance will be launched in the default galactic VPC with automatic IP assignment via quantum DHCP.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">💾 Storage Configuration</div>
                        <div class="card-body">
                            <div class="info-box">
                                <div class="info-box-title">🗄️ Primary Storage Bay</div>
                                <div class="info-box-content">
                                    4 GB quantum storage • Auto-scaling enabled • Cosmic redundancy: Yes
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="launch-button">🚀 Launch Into Orbit</button>
                </form>
            </div>

            <div class="sidebar">
                <div class="card">
                    <div class="card-header">📊 Mission Summary</div>
                    <div class="card-body">
                        <div style="margin-bottom: 15px;">
                            <strong>🔧 Instance Type:</strong><br>
                            <span style="color: #90caf9;">Nebula.micro</span>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <strong>🖥️ OS Image:</strong><br>
                            <span style="color: #90caf9;">Ubuntu 20.04 LTS Stellar</span>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <strong>💾 Storage:</strong><br>
                            <span style="color: #90caf9;">4 GB Quantum Drive</span>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <strong>🌐 Network:</strong><br>
                            <span style="color: #90caf9;">Galactic VPC</span>
                        </div>
                    </div>
                </div>

                <div class="price-info">
                    <div class="price-title">💰 Cosmic Pricing</div>
                    <div class="price-details">
                        <strong>🆓 Free Tier:</strong> 750 hours per lunar cycle
                        <br><br>
                        <strong>⭐ Premium Rate:</strong> 0.0116 stellar credits/hour
                        <br><br>
                        <strong>🌟 Current Status:</strong> Free tier eligible
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Generate random stars
        function createStars() {
            const starsContainer = document.querySelector('.stars');
            const numStars = 150;

            for (let i = 0; i < numStars; i++) {
                const star = document.createElement('div');
                star.className = 'star';
                star.style.left = Math.random() * 100 + '%';
                star.style.top = Math.random() * 100 + '%';
                star.style.width = Math.random() * 3 + 1 + 'px';
                star.style.height = star.style.width;
                star.style.animationDelay = Math.random() * 3 + 's';
                starsContainer.appendChild(star);
            }
        }

        // Form submission with cosmic loading
        document.querySelector('form').addEventListener('submit', function(e) {
            const btn = document.querySelector('.launch-button');
            btn.innerHTML = '🌌 Initiating Launch Sequence...';
            btn.style.background = 'linear-gradient(45deg, #ff6b35, #f7931e, #ffd700)';
        });

        // Initialize cosmic environment
        window.addEventListener('load', createStars);
    </script>
</body>
</html>