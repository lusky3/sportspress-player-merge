const fetch = require('node-fetch');

async function test() {
    console.log('🚀 Starting Ultra-Simple Test Suite...\n');
    
    const baseUrl = process.env.TEST_URL || 'http://wordpress:80';
    console.log(`Using base URL: ${baseUrl}`);
    
    let attempts = 0;
    while (attempts < 30) { // Reduced to 1 minute
        try {
            const response = await fetch(baseUrl, { timeout: 3000 });
            if (response.ok) {
                console.log('✅ WordPress is responding');
                console.log('✅ Basic connectivity test passed!');
                process.exit(0);
            }
        } catch (error) {
            console.log(`⏳ Attempt ${attempts + 1}/30: ${error.message}`);
        }
        attempts++;
        await new Promise(resolve => setTimeout(resolve, 2000));
    }
    
    console.error('❌ WordPress failed to start after 1 minute');
    process.exit(1);
}

test();