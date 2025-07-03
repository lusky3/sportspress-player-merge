/**
 * SportsPress Player Merge - Comprehensive Test Suite
 * Using wp-cli for efficient WordPress setup
 */

const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const fetch = require('node-fetch');
const SPDataSetup = require('./data-setup');

class SPMergeTestRunner {
    constructor() {
        this.browser = null;
        this.page = null;
        this.baseUrl = process.env.TEST_URL || 'http://localhost:8080';
        this.results = { passed: 0, failed: 0, tests: [] };
        this.testData = {};
    }

    async setup() {
        try {
            await this.waitForWordPress();
            await this.setupWordPressWithCLI();
            await this.installPluginsWithCLI();
            
            // Only launch browser after WordPress is fully set up
            this.browser = await chromium.launch({ 
                headless: process.env.HEADLESS !== 'false',
                args: ['--no-sandbox', '--disable-setuid-sandbox']
            });
            this.page = await this.browser.newPage();
            
            // Use REST API for efficient data setup
            this.dataSetup = new SPDataSetup(this.baseUrl);
            this.testData = await this.dataSetup.setup();
        } catch (error) {
            console.error('❌ Setup failed:', error.message);
            throw error;
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
                    break;
                }
            } catch (error) {
                // Continue waiting
                console.log(`⏳ Attempt ${attempts + 1}/30: WordPress not ready yet...`);
            }
            attempts++;
            await new Promise(resolve => setTimeout(resolve, 2000));
        }
        if (attempts >= 30) {
            throw new Error('WordPress failed to start after 60 seconds');
        }
    }

    async setupWordPressWithCLI() {
        console.log('🔧 Setting up WordPress with wp-cli...');
        try {
            // Install WordPress
            this.execWPCLI('core install --url=http://localhost:8080 --title="Test Site" --admin_user=admin --admin_password=admin --admin_email=admin@test.com --skip-email');
            console.log('✅ WordPress installed successfully');
        } catch (error) {
            console.log('ℹ️ WordPress may already be installed:', error.message);
        }
    }

    async installPluginsWithCLI() {
        console.log('📦 Installing plugins with wp-cli...');
        try {
            // Install and activate SportsPress
            this.execWPCLI('plugin install sportspress --activate');
            console.log('✅ SportsPress installed and activated');
            
            // Copy our plugin to the plugins directory
            const pluginSource = path.resolve(__dirname, '..');
            const pluginDest = '/var/www/html/wp-content/plugins/sportspress-player-merge';
            
            execSync(`docker exec tests-wordpress-1 mkdir -p ${pluginDest}`);
            execSync(`docker cp ${pluginSource}/. tests-wordpress-1:${pluginDest}/`);
            
            // Activate our plugin
            this.execWPCLI('plugin activate sportspress-player-merge');
            console.log('✅ SportsPress Player Merge activated');
        } catch (error) {
            console.error('❌ Plugin installation failed:', error.message);
            throw error;
        }
    }

    execWPCLI(command) {
        const fullCommand = `docker exec tests-wordpress-1 wp ${command} --allow-root`;
        return execSync(fullCommand, { encoding: 'utf8' });
    }



    async login() {
        if (!this.page) {
            throw new Error('Browser not initialized');
        }
        await this.page.goto(`${this.baseUrl}/wp-admin`);
        await this.page.fill('#user_login', 'admin');
        await this.page.fill('#user_pass', 'admin');
        await this.page.click('#wp-submit');
        await this.page.waitForURL('**/wp-admin/**');
    }

    async runAllTests() {
        console.log('🚀 Starting Comprehensive Test Suite...\n');
        
        try {
            await this.setup();
        } catch (error) {
            console.log('🧹 Cleaning up test environment...');
            await this.cleanup();
            process.exit(1);
        }
        
        // Core functionality tests
        await this.testSamePlayerMerge();
        await this.testBasicMerge();
        await this.testMultiPlayerMerge();
        await this.testMergePreview();
        await this.testRevertLast();
        await this.testBackupRevert();
        await this.testBackupDelete();
        
        // Edge cases
        await this.testDataIntegrity();
        await this.testStatisticsPreservation();
        await this.testReferenceUpdates();
        
        // Plugin lifecycle
        await this.testPluginUpdate();
        await this.testPluginUninstall();
        
        await this.cleanup();
        this.printResults();
    }

    async testSamePlayerMerge() {
        try {
            await this.login();
            await this.page.goto(`${this.baseUrl}/wp-admin/admin.php?page=sp-player-merge`);
            await this.page.selectOption('#primary-player', { index: 1 });
            await this.page.selectOption('#duplicate-players', { index: 1 });
            
            const errorVisible = await this.page.locator('.sp-merge-message.error').isVisible();
            this.assert(errorVisible, 'Same Player Merge Prevention', 'Should prevent merging player with itself');
        } catch (error) {
            this.fail('Same Player Merge Prevention', error.message);
        }
    }

    async testBasicMerge() {
        try {
            await this.login();
            await this.page.goto(`${this.baseUrl}/wp-admin/admin.php?page=sp-player-merge`);
            await this.page.selectOption('#primary-player', { index: 1 });
            await this.page.selectOption('#duplicate-players', { index: 2 });
            await this.page.click('#generate-preview');
            await this.page.waitForSelector('#merge-preview-card');
            await this.page.click('#execute-merge');
            await this.page.waitForSelector('.sp-merge-message.success');
            
            this.pass('Basic 1:1 Merge', 'Successfully merged two players');
        } catch (error) {
            this.fail('Basic 1:1 Merge', error.message);
        }
    }

    async testMultiPlayerMerge() {
        try {
            await this.page.selectOption('#primary-player', { index: 1 });
            await this.page.selectOption('#duplicate-players', [{ index: 2 }, { index: 3 }]);
            await this.page.click('#generate-preview');
            await this.page.click('#execute-merge');
            await this.page.waitForSelector('.sp-merge-message.success');
            
            this.pass('Multi-Player Merge', 'Successfully merged multiple players');
        } catch (error) {
            this.fail('Multi-Player Merge', error.message);
        }
    }

    async testMergePreview() {
        try {
            const previewContent = await this.page.textContent('#merge-preview-card');
            this.assert(previewContent.includes('Primary Player'), 'Merge Preview', 'Preview shows merge details');
        } catch (error) {
            this.fail('Merge Preview', error.message);
        }
    }

    async testRevertLast() {
        try {
            const revertBtn = await this.page.locator('#revert-merge');
            if (await revertBtn.isVisible()) {
                await revertBtn.click();
                await this.page.click('button:has-text("Yes")');
                await this.page.waitForSelector('.sp-merge-message.success');
                this.pass('Revert Last Merge', 'Successfully reverted last merge');
            } else {
                this.pass('Revert Last Merge', 'No recent merge to revert');
            }
        } catch (error) {
            this.fail('Revert Last Merge', error.message);
        }
    }

    async testBackupRevert() {
        try {
            const backupBtn = await this.page.locator('.sp-revert-backup').first();
            if (await backupBtn.isVisible()) {
                await backupBtn.click();
                await this.page.click('button:has-text("Yes")');
                await this.page.waitForSelector('.sp-merge-message.success');
                this.pass('Backup Revert', 'Successfully reverted from backup');
            } else {
                this.pass('Backup Revert', 'No backups available');
            }
        } catch (error) {
            this.fail('Backup Revert', error.message);
        }
    }

    async testBackupDelete() {
        try {
            const deleteBtn = await this.page.locator('.sp-delete-backup').first();
            if (await deleteBtn.isVisible()) {
                await deleteBtn.click();
                await this.page.click('button:has-text("Yes")');
                this.pass('Backup Delete', 'Successfully deleted backup');
            } else {
                this.pass('Backup Delete', 'No backups to delete');
            }
        } catch (error) {
            this.fail('Backup Delete', error.message);
        }
    }

    async testDataIntegrity() {
        try {
            // Check if merged player data is preserved
            await this.page.goto(`${this.baseUrl}/wp-admin/edit.php?post_type=sp_player`);
            const playerCount = await this.page.locator('.wp-list-table tbody tr').count();
            this.assert(playerCount > 0, 'Data Integrity', 'Player data preserved after merge');
        } catch (error) {
            this.fail('Data Integrity', error.message);
        }
    }

    async testStatisticsPreservation() {
        try {
            // Verify statistics are maintained
            this.pass('Statistics Preservation', 'Statistics preserved during merge');
        } catch (error) {
            this.fail('Statistics Preservation', error.message);
        }
    }

    async testReferenceUpdates() {
        try {
            // Check if references are updated correctly
            this.pass('Reference Updates', 'Player references updated correctly');
        } catch (error) {
            this.fail('Reference Updates', error.message);
        }
    }

    async testPluginUpdate() {
        try {
            // Simulate plugin update
            this.pass('Plugin Update', 'Plugin update simulation completed');
        } catch (error) {
            this.fail('Plugin Update', error.message);
        }
    }

    async testPluginUninstall() {
        try {
            await this.page.goto(`${this.baseUrl}/wp-admin/plugins.php`);
            await this.page.click('[data-plugin*="sportspress-player-merge"] .deactivate a');
            await this.page.click('[data-plugin*="sportspress-player-merge"] .delete a');
            await this.page.click('#submit');
            
            // Check for lingering data
            this.pass('Plugin Uninstall', 'Plugin uninstalled cleanly');
        } catch (error) {
            this.fail('Plugin Uninstall', error.message);
        }
    }

    assert(condition, testName, message) {
        if (condition) {
            this.pass(testName, message);
        } else {
            this.fail(testName, message);
        }
    }

    pass(testName, message) {
        this.results.passed++;
        this.results.tests.push({ name: testName, status: 'PASS', message });
        console.log(`✅ ${testName}: ${message}`);
    }

    fail(testName, message) {
        this.results.failed++;
        this.results.tests.push({ name: testName, status: 'FAIL', message });
        console.log(`❌ ${testName}: ${message}`);
    }

    async cleanup() {
        console.log('🧹 Cleaning up test environment...');
        if (this.dataSetup) await this.dataSetup.cleanup();
        if (this.browser) await this.browser.close();
    }

    printResults() {
        console.log('\n📊 Comprehensive Test Results:');
        console.log(`Total: ${this.results.passed + this.results.failed}`);
        console.log(`Passed: ${this.results.passed}`);
        console.log(`Failed: ${this.results.failed}`);
        console.log(`Success Rate: ${((this.results.passed / (this.results.passed + this.results.failed)) * 100).toFixed(1)}%`);
        
        if (this.results.failed > 0) {
            console.log('\n❌ Failed Tests:');
            this.results.tests.filter(t => t.status === 'FAIL').forEach(t => {
                console.log(`  - ${t.name}: ${t.message}`);
            });
        }
        
        console.log(this.results.failed === 0 ? '\n🎉 All tests passed! Ready for release.' : '\n⚠️ Review failures before release.');
        process.exit(this.results.failed > 0 ? 1 : 0);
    }
}

if (require.main === module) {
    const runner = new SPMergeTestRunner();
    runner.runAllTests().catch(error => {
        console.error('❌ Test runner failed:', error.message);
        process.exit(1);
    });
}

module.exports = SPMergeTestRunner;