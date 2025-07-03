/**
 * Simplified Test Runner - Verify wp-cli approach
 */

const { execSync } = require('child_process');
const fetch = require('node-fetch');

class SimpleTestRunner {
    constructor() {
        this.baseUrl = process.env.TEST_URL || 'http://localhost:8080';
    }

    async run() {
        console.log('🚀 Starting Simple Test Suite...\n');
        
        try {
            await this.waitForWordPress();
            await this.setupWordPressWithCLI();
            await this.installPluginsWithCLI();
            await this.verifySetup();
            
            console.log('\n✅ All setup tests passed! WordPress and plugins are ready.');
            process.exit(0);
        } catch (error) {
            console.error('\n❌ Setup failed:', error.message);
            process.exit(1);
        }
    }

    async waitForWordPress() {
        console.log('⏳ Waiting for WordPress...');
        let attempts = 0;
        while (attempts < 30) {
            try {
                const response = await fetch(this.baseUrl);
                if (response.ok) {
                    console.log('✅ WordPress is responding');
                    return;
                }
            } catch (error) {
                console.log(`⏳ Attempt ${attempts + 1}/30: WordPress not ready yet...`);
            }
            attempts++;
            await new Promise(resolve => setTimeout(resolve, 2000));
        }
        throw new Error('WordPress failed to start after 60 seconds');
    }

    async setupWordPressWithCLI() {
        console.log('🔧 Setting up WordPress with wp-cli...');
        try {
            // Check if WordPress is already installed
            try {
                this.execWPCLI('core is-installed');
                console.log('ℹ️ WordPress is already installed');
                return;
            } catch {
                // WordPress not installed, proceed with installation
            }

            // Install WordPress
            this.execWPCLI('core install --url=http://localhost:8080 --title="Test Site" --admin_user=admin --admin_password=admin --admin_email=admin@test.com --skip-email');
            console.log('✅ WordPress installed successfully');
        } catch (error) {
            throw new Error(`WordPress setup failed: ${error.message}`);
        }
    }

    async installPluginsWithCLI() {
        console.log('📦 Installing plugins with wp-cli...');
        try {
            // Install and activate SportsPress
            try {
                this.execWPCLI('plugin is-installed sportspress');
                console.log('ℹ️ SportsPress is already installed');
            } catch {
                this.execWPCLI('plugin install sportspress --activate');
                console.log('✅ SportsPress installed and activated');
            }
            
            // Activate our plugin (it should already be mounted)
            try {
                this.execWPCLI('plugin activate sportspress-player-merge');
                console.log('✅ SportsPress Player Merge activated');
            } catch (error) {
                console.log('⚠️ Could not activate SportsPress Player Merge:', error.message);
                // List available plugins for debugging
                console.log('Available plugins:');
                console.log(this.execWPCLI('plugin list'));
            }
        } catch (error) {
            throw new Error(`Plugin installation failed: ${error.message}`);
        }
    }

    async verifySetup() {
        console.log('🔍 Verifying setup...');
        
        // Check WordPress version
        const wpVersion = this.execWPCLI('core version');
        console.log(`✅ WordPress version: ${wpVersion.trim()}`);
        
        // Check active plugins
        const plugins = this.execWPCLI('plugin list --status=active --format=table');
        console.log('✅ Active plugins:');
        console.log(plugins);
        
        // Check if admin user exists
        const users = this.execWPCLI('user list --format=table');
        console.log('✅ Users:');
        console.log(users);
    }

    execWPCLI(command) {
        const fullCommand = `docker exec tests-wordpress-1 wp ${command} --allow-root`;
        console.log(`Running: ${fullCommand}`);
        return execSync(fullCommand, { encoding: 'utf8' });
    }
}

if (require.main === module) {
    const runner = new SimpleTestRunner();
    runner.run().catch(error => {
        console.error('❌ Test runner failed:', error.message);
        process.exit(1);
    });
}

module.exports = SimpleTestRunner;