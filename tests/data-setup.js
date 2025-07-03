/**
 * SportsPress Test Data Setup via REST API
 * More efficient than Playwright UI automation
 */

const axios = require('axios');

class SPDataSetup {
    constructor(baseUrl = 'http://localhost:8080') {
        this.baseUrl = baseUrl;
        this.auth = { username: 'admin', password: 'admin' };
        this.createdData = {
            leagues: [],
            seasons: [],
            teams: [],
            players: [],
            events: [],
            lists: []
        };
    }

    async setup() {
        console.log('🏗️ Setting up SportsPress test data via REST API...');
        
        await this.createTaxonomies();
        await this.createTeams();
        await this.createPlayers();
        await this.createEvents();
        await this.createPlayerLists();
        await this.linkPlayersToEvents();
        
        console.log('✅ Test data setup complete');
        return this.createdData;
    }

    async createTaxonomies() {
        // Create leagues
        const leagues = ['Premier League', 'Championship'];
        for (const league of leagues) {
            const response = await this.post('/wp-json/wp/v2/sp_league', {
                name: league,
                slug: league.toLowerCase().replace(' ', '-')
            });
            this.createdData.leagues.push(response.data);
        }

        // Create seasons
        const seasons = ['2023', '2024'];
        for (const season of seasons) {
            const response = await this.post('/wp-json/wp/v2/sp_season', {
                name: season,
                slug: season
            });
            this.createdData.seasons.push(response.data);
        }

        // Create positions
        const positions = ['Forward', 'Midfielder', 'Defender', 'Goalkeeper'];
        for (const position of positions) {
            await this.post('/wp-json/wp/v2/sp_position', {
                name: position,
                slug: position.toLowerCase()
            });
        }
    }

    async createTeams() {
        const teams = [
            { name: 'Arsenal FC', abbreviation: 'ARS' },
            { name: 'Chelsea FC', abbreviation: 'CHE' },
            { name: 'Liverpool FC', abbreviation: 'LIV' }
        ];

        for (const team of teams) {
            const response = await this.post('/wp-json/wp/v2/sp_team', {
                title: { rendered: team.name },
                status: 'publish',
                meta: {
                    sp_abbreviation: team.abbreviation
                },
                sp_league: [this.createdData.leagues[0].id],
                sp_season: [this.createdData.seasons[1].id]
            });
            this.createdData.teams.push(response.data);
        }
    }

    async createPlayers() {
        const players = [
            // Primary players
            { name: 'John Smith', number: 10, team: 0, position: 'Forward' },
            { name: 'Mike Johnson', number: 7, team: 1, position: 'Midfielder' },
            
            // Duplicates for testing
            { name: 'John Smith', number: 10, team: 0, position: 'Forward' }, // Duplicate of first
            { name: 'John Smith Jr', number: 11, team: 0, position: 'Forward' }, // Similar name
            { name: 'Mike Johnson', number: 8, team: 1, position: 'Midfielder' }, // Duplicate with different number
            
            // Additional players for complex scenarios
            { name: 'David Wilson', number: 9, team: 2, position: 'Forward' },
            { name: 'Tom Brown', number: 4, team: 0, position: 'Defender' },
            { name: 'Alex Green', number: 1, team: 1, position: 'Goalkeeper' }
        ];

        for (const player of players) {
            const response = await this.post('/wp-json/wp/v2/sp_player', {
                title: { rendered: player.name },
                status: 'publish',
                meta: {
                    sp_number: player.number,
                    sp_current_team: this.createdData.teams[player.team].id,
                    sp_leagues: {
                        [this.createdData.leagues[0].id]: {
                            [this.createdData.seasons[1].id]: this.createdData.teams[player.team].id
                        }
                    },
                    sp_assignments: [`${this.createdData.leagues[0].id}_${this.createdData.seasons[1].id}_${this.createdData.teams[player.team].id}`]
                },
                sp_league: [this.createdData.leagues[0].id],
                sp_season: [this.createdData.seasons[1].id],
                sp_team: [this.createdData.teams[player.team].id]
            });
            this.createdData.players.push(response.data);
        }
    }

    async createEvents() {
        const events = [
            {
                title: 'Arsenal vs Chelsea',
                teams: [0, 1],
                date: '2024-01-15',
                results: { [this.createdData.teams[0].id]: { outcome: 'win' }, [this.createdData.teams[1].id]: { outcome: 'loss' } }
            },
            {
                title: 'Liverpool vs Arsenal', 
                teams: [2, 0],
                date: '2024-01-22',
                results: { [this.createdData.teams[2].id]: { outcome: 'draw' }, [this.createdData.teams[0].id]: { outcome: 'draw' } }
            }
        ];

        for (const event of events) {
            const response = await this.post('/wp-json/wp/v2/sp_event', {
                title: { rendered: event.title },
                status: 'publish',
                meta: {
                    sp_date: event.date,
                    sp_time: '15:00',
                    sp_results: event.results,
                    sp_format: 'league',
                    sp_mode: 'team'
                },
                sp_league: [this.createdData.leagues[0].id],
                sp_season: [this.createdData.seasons[1].id],
                sp_team: event.teams.map(i => this.createdData.teams[i].id)
            });
            this.createdData.events.push(response.data);
        }
    }

    async createPlayerLists() {
        const lists = [
            { name: 'Arsenal Squad', team: 0, players: [0, 2, 3, 6] },
            { name: 'Chelsea Squad', team: 1, players: [1, 4, 7] },
            { name: 'Liverpool Squad', team: 2, players: [5] }
        ];

        for (const list of lists) {
            const playerIds = list.players.map(i => this.createdData.players[i].id);
            const response = await this.post('/wp-json/wp/v2/sp_list', {
                title: { rendered: list.name },
                status: 'publish',
                meta: {
                    sp_player: playerIds,
                    sp_format: 'list'
                },
                sp_league: [this.createdData.leagues[0].id],
                sp_season: [this.createdData.seasons[1].id],
                sp_team: [this.createdData.teams[list.team].id]
            });
            this.createdData.lists.push(response.data);
        }
    }

    async linkPlayersToEvents() {
        // Add player performance data to events
        for (const event of this.createdData.events) {
            const playerData = {};
            
            // Get teams for this event
            const eventTeams = event.sp_team || [];
            
            eventTeams.forEach(teamId => {
                playerData[teamId] = {};
                
                // Find players for this team
                const teamPlayers = this.createdData.players.filter(p => 
                    p.meta.sp_current_team == teamId
                );
                
                teamPlayers.forEach(player => {
                    playerData[teamId][player.id] = {
                        g: Math.floor(Math.random() * 3), // Goals
                        a: Math.floor(Math.random() * 2), // Assists
                        gp: 1, // Games played
                        pim: Math.floor(Math.random() * 5) // Penalty minutes
                    };
                });
            });

            // Update event with player data
            await this.put(`/wp-json/wp/v2/sp_event/${event.id}`, {
                meta: {
                    ...event.meta,
                    sp_players: playerData,
                    sp_player: this.createdData.players.map(p => p.id)
                }
            });
        }
    }

    async post(endpoint, data) {
        return await axios.post(`${this.baseUrl}${endpoint}`, data, {
            auth: this.auth,
            headers: { 'Content-Type': 'application/json' }
        });
    }

    async put(endpoint, data) {
        return await axios.put(`${this.baseUrl}${endpoint}`, data, {
            auth: this.auth,
            headers: { 'Content-Type': 'application/json' }
        });
    }

    async cleanup() {
        console.log('🧹 Cleaning up test data...');
        
        // Delete in reverse order to handle dependencies
        for (const list of this.createdData.lists) {
            await this.delete(`/wp-json/wp/v2/sp_list/${list.id}?force=true`);
        }
        
        for (const event of this.createdData.events) {
            await this.delete(`/wp-json/wp/v2/sp_event/${event.id}?force=true`);
        }
        
        for (const player of this.createdData.players) {
            await this.delete(`/wp-json/wp/v2/sp_player/${player.id}?force=true`);
        }
        
        for (const team of this.createdData.teams) {
            await this.delete(`/wp-json/wp/v2/sp_team/${team.id}?force=true`);
        }
        
        for (const season of this.createdData.seasons) {
            await this.delete(`/wp-json/wp/v2/sp_season/${season.id}?force=true`);
        }
        
        for (const league of this.createdData.leagues) {
            await this.delete(`/wp-json/wp/v2/sp_league/${league.id}?force=true`);
        }
        
        console.log('✅ Cleanup complete');
    }

    async delete(endpoint) {
        try {
            await axios.delete(`${this.baseUrl}${endpoint}`, { auth: this.auth });
        } catch (error) {
            // Ignore 404s during cleanup
            if (error.response?.status !== 404) {
                console.warn(`Cleanup warning: ${error.message}`);
            }
        }
    }
}

module.exports = SPDataSetup;