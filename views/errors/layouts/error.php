<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $errorCode ?> - <?= $errorTitle ?></title>
    <link rel="stylesheet" href="/public/sneat/assets/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f5f7ff 0%, #f5f5f9 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        
        .error-container {
            text-align: center;
            padding: 3rem;
            max-width: 600px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            backdrop-filter: blur(10px);
            animation: fadeIn 1s ease;
        }

        .error-code {
            font-size: 8rem;
            font-weight: 800;
            background: linear-gradient(45deg, #696cff, #8789ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
            line-height: 1;
            text-shadow: 2px 2px 30px rgba(105, 108, 255, 0.2);
            animation: bounceIn 1s ease;
        }

        .error-title {
            font-size: 2rem;
            font-weight: 600;
            color: #566a7f;
            margin-bottom: 1rem;
            animation: fadeInUp 0.4s ease 0.1s both;
        }

        .error-message {
            color: #697a8d;
            margin-bottom: 2.5rem;
            font-size: 1.1rem;
            line-height: 1.6;
            animation: fadeInUp 0.4s ease 0.2s both;
        }

        .back-home {
            background: linear-gradient(45deg, #696cff, #8789ff);
            color: white;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 1.1rem;
            box-shadow: 0 5px 20px rgba(105, 108, 255, 0.3);
            animation: fadeInUp 0.6s ease 0.6s both;
            display: inline-block;
        }

        .back-home:hover {
            background: linear-gradient(45deg, #5f62e6, #7375ff);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(105, 108, 255, 0.4);
        }

        .error-image {
            max-width: 380px;
            margin-bottom: 2.5rem;
            animation: float 6s ease-in-out infinite;
            filter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.1));
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-20px);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            background: rgba(105, 108, 255, 0.2);
            pointer-events: none;
            z-index: -1;
        }

        @media (max-width: 768px) {
            .error-container {
                padding: 2rem;
                margin: 1rem;
            }
            
            .error-code {
                font-size: 6rem;
            }

            .error-title {
                font-size: 1.5rem;
            }

            .error-image {
                max-width: 280px;
            }
        }
    </style>
</head>

<body>
    <div id="particles"></div>
    <div class="error-container animate__animated animate__fadeIn">
        <?php if (isset($errorImage)): ?>
            <img src="<?= $errorImage ?>" alt="Error Image" class="error-image">
        <?php endif; ?>
        <div class="error-code animate__animated animate__bounceIn"><?= $errorCode ?></div>
        <div class="error-title animate__animated animate__fadeInUp animate__delay-1s"><?= $errorTitle ?></div>
        <div class="error-message animate__animated animate__fadeInUp animate__delay-2s"><?= $errorMessage ?></div>
        <a href="javascript:void(0)" onclick="window.history.back();" class="back-home animate__animated animate__fadeInUp animate__delay-3s">
            Back to Previous Page
        </a>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
    <script>
        // Create floating particles
        function createParticle() {
            const particles = document.getElementById('particles');
            const particle = document.createElement('div');
            particle.className = 'particle';
            
            // Random size between 5 and 20
            const size = Math.random() * 15 + 5;
            particle.style.width = `${size}px`;
            particle.style.height = `${size}px`;
            
            // Random position
            const x = Math.random() * window.innerWidth;
            const y = Math.random() * window.innerHeight;
            particle.style.left = `${x}px`;
            particle.style.top = `${y}px`;
            
            // Add to DOM
            particles.appendChild(particle);
            
            // Animate
            const animation = particle.animate([
                { transform: 'translate(0, 0)', opacity: 0.8 },
                { transform: `translate(${Math.random() * 200 - 100}px, ${Math.random() * 200 - 100}px)`, opacity: 0 }
            ], {
                duration: Math.random() * 3000 + 2000,
                easing: 'cubic-bezier(0.4, 0.0, 0.2, 1)'
            });
            
            // Remove particle after animation
            animation.onfinish = () => particle.remove();
        }

        // Create particles periodically
        setInterval(createParticle, 300);
    </script>
</body>

</html>
