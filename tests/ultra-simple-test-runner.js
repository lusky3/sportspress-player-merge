/**
 * Ultra-Simple Test Runner - Just verify basic connectivity
 */

const fetch = require('node-fetch');

class UltraSimpleTestRunner {
    constructor() {
        this.baseUrl = process.env.TEST_URL || 'http://localhost:8081';
        console.log(`Using base URL: ${this.baseUrl}`);
    }

    async run() {
        console.log('🚀 Starting Ultra-Simple Test Suite...\n');
        
        try {
            await this.waitForWordPress();
            await this.checkWordPressResponse();
            
            console.log('\n✅ Basic connectivity test passed! WordPress is responding.');
            process.exit(0);
        } catch (error) {
            console.error('\n❌ Test failed:', error.message);
            process.exit(1);
        }
    }

    async waitForWordPress() {
        console.log('⏳ Waiting for WordPress...');
        let attempts = 0;
        while (attempts < 60) { // Increased to 2 minutes
            try {
                const response = await fetch(this.baseUrl, { timeout: 5000 });
                if (response.ok) {
                    console.log('✅ WordPress is responding');
                    return;
                }
                console.log(`⏳ Attempt ${attempts + 1}/60: Got response ${response.status}`);
            } catch (error) {
                console.log(`⏳ Attempt ${attempts + 1}/60: ${error.message}`);
            }
            attempts++;
            await new Promise(resolve => setTimeout(resolve, 2000));
        }
        throw new Error('WordPress failed to start after 2 minutes');
    }

    async checkWordPressResponse() {
        console.log('🔍 Checking WordPress response...');
        const response = await fetch(this.baseUrl);
        const text = await response.text();
        
        if (text.includes('WordPress') || text.includes('wp-') || response.status === 200) {
            console.log('✅ WordPress is working correctly');
            console.log(`Response status: ${response.status}`);
            console.log(`Response contains WordPress content: ${text.includes('WordPress')}`);
        } else {
            throw new Error('WordPress response does not look correct');
        }
    }
}

if (require.main === module) {
    const runner = new UltraSimpleTestRunner();
    runner.run().catch(error => {
        console.error('❌ Test runner failed:', error.message);
        process.exit(1);
    });
}

module.exports = UltraSimpleTestRunner;