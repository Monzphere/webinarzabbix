class LocationMapViewer {
    constructor() {
        this.map = null;
        this.messages = null;
        this.mapElement = null;
    }

    init() {
        const currentUrl = new URL(window.location.href);
        const action = currentUrl.searchParams.get('action');
        
        if (action !== 'map.view') {
            console.log('Not in map view, skipping initialization');
            return;
        }

        this.mapElement = document.getElementById('map');
        if (!this.mapElement) {
            console.log('Map element not found, skipping initialization');
            return;
        }

        this.messages = document.getElementById('messageBox');
        this.initializeMap();
    }

    async initializeMap() {
        try {
            await this.checkLeaflet();
            this.map = await this.createMap();
            await this.loadHostData();
        }
        catch (error) {
            console.error('Error initializing map:', error);
            this.showError(error.message);
        }
    }

    checkLeaflet() {
        return new Promise((resolve) => {
            if (window.L) {
                console.log('Leaflet loaded successfully');
                resolve();
            } else {
                console.log('Waiting for Leaflet to load...');
                setTimeout(() => this.checkLeaflet().then(resolve), 100);
            }
        });
    }

    async createMap() {
        if (this.map) {
            this.map.remove();
        }

        const map = L.map('map').setView([-23.5505, -46.6333], 4);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        return map;
    }

    async loadHostData() {
        const urlParams = new URLSearchParams(window.location.search);
        const groupids = urlParams.get('groupids');
        const filterSet = urlParams.get('filter_set');

        if (!groupids || !filterSet) {
            return;
        }

        try {
            const response = await fetch(`zabbix.php?action=map.view&groupids=${groupids}`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            if (data.hosts && data.stats) {
                this.renderStats(data.stats);
                this.addMarkers(data.hosts);
                this.adjustMapBounds(data.hosts);
            }
        }
        catch (error) {
            this.showError(error.message);
        }
    }

    renderStats(stats) {
        const statsHtml = `
            <div class="map-stats">
                <div class="stats-row">
                    <div class="stats-item">
                        <i class="stats-icon fas fa-server"></i>
                        <div class="stats-content">
                            <div class="stats-label">Total Hosts</div>
                            <div class="stats-value">${stats.total_hosts}</div>
                            <div class="stats-details"></div>
                        </div>
                    </div>
                    <div class="stats-item">
                        <i class="stats-icon fas fa-map-marker-alt"></i>
                        <div class="stats-content">
                            <div class="stats-label">Hosts Mapeados</div>
                            <div class="stats-value">${stats.hosts_with_coords}</div>
                            <div class="stats-progress">
                                <div class="progress-bar" style="width: ${(stats.hosts_with_coords/stats.total_hosts*100).toFixed(1)}%"></div>
                            </div>
                            <div class="stats-details">
                                <span>${((stats.hosts_with_coords/stats.total_hosts)*100).toFixed(1)}% do total</span>
                            </div>
                        </div>
                    </div>
                    <div class="stats-item">
                        <i class="stats-icon fas fa-exclamation-triangle"></i>
                        <div class="stats-content">
                            <div class="stats-label">Total Problems</div>
                            <div class="stats-value">${stats.problems_total}</div>
                        </div>
                    </div>
                </div>
                <div class="stats-row problems">
                    ${this.renderProblemStats(stats.problems)}
                </div>
                <div class="stats-footer">
                    <i class="fas fa-clock"></i> 
                    Last update: ${stats.last_update}
                </div>
            </div>
        `;

        const statsContainer = document.createElement('div');
        statsContainer.innerHTML = statsHtml;
        this.mapElement.parentNode.insertBefore(statsContainer, this.mapElement);
    }

    renderProblemStats(problems) {
        return Object.entries(problems).map(([severity, count]) => `
            <div class="stats-item severity-${severity}">
                <i class="stats-icon ${this.getSeverityIcon(severity)}"></i>
                <div class="stats-content">
                    <div class="stats-label">${this.getSeverityLabel(severity)}</div>
                    <div class="stats-value">${count}</div>
                </div>
            </div>
        `).join('');
    }

    addMarkers(hosts) {
        if (!Array.isArray(hosts)) {
            console.error('Hosts is not an array:', hosts);
            return;
        }

        hosts.forEach(host => {
            if (!this.hasValidCoordinates(host)) {
                return;
            }

            const lat = parseFloat(host.inventory.location_lat);
            const lon = parseFloat(host.inventory.location_lon);

            try {
                host.problems = host.problems || 0;
                host.max_severity = host.max_severity || 0;

                const markerIcon = this.createMarkerIcon(host);
                const marker = L.marker([lat, lon], {
                    icon: markerIcon,
                    riseOnHover: true,
                    title: host.name
                }).addTo(this.map);

                marker.bindPopup(this.createPopupContent(host), {
                    maxWidth: 300,
                    className: 'host-marker-popup'
                });
            }
            catch (error) {
                console.error(`Error creating marker for host ${host.name}:`, error);
            }
        });
    }

    hasValidCoordinates(host) {
        if (!host.inventory?.location_lat || !host.inventory?.location_lon) {
            return false;
        }

        const lat = parseFloat(host.inventory.location_lat);
        const lon = parseFloat(host.inventory.location_lon);

        return !isNaN(lat) && !isNaN(lon);
    }

    createMarkerIcon(host) {
        let markerClass = 'no-problems';
        if (!host.is_enabled) {
            markerClass = 'disabled';
        } else if (host.problems > 0) {
            markerClass = 'has-problems';
        }

        return L.divIcon({
            className: 'custom-marker',
            html: `
                <div class="marker-container ${markerClass}">
                    <i class="fas fa-map-marker"></i>
                    <span class="problem-count severity-${host.max_severity}">${host.problems}</span>
                </div>
            `,
            iconSize: [40, 56],
            iconAnchor: [20, 56],
            popupAnchor: [0, -56]
        });
    }

    createPopupContent(host) {
        const ip = host.interfaces?.[0]?.ip || 'N/A';
        return `
            <div class="host-popup">
                <h4>${host.name}</h4>
                <div class="host-popup-content">
                    <div class="host-popup-row">
                        <span class="host-popup-label">Status</span>
                        <span class="host-popup-value">${host.is_enabled ? 'Enabled' : 'Disabled'}</span>
                    </div>
                    <div class="host-popup-row">
                        <span class="host-popup-label">IP</span>
                        <span class="host-popup-value">${ip}</span>
                    </div>
                    <div class="host-popup-row">
                        <span class="host-popup-label">Latitude</span>
                        <span class="host-popup-value">${host.inventory.location_lat}</span>
                    </div>
                    <div class="host-popup-row">
                        <span class="host-popup-label">Longitude</span>
                        <span class="host-popup-value">${host.inventory.location_lon}</span>
                    </div>
                    <div class="host-popup-row problems-row severity-${host.max_severity}">
                        <span class="host-popup-label">Problems</span>
                        <span class="host-popup-value">${host.problems}</span>
                    </div>
                </div>
            </div>
        `;
    }

    adjustMapBounds(hosts) {
        const bounds = [];
        hosts.forEach(host => {
            if (this.hasValidCoordinates(host)) {
                const lat = parseFloat(host.inventory.location_lat);
                const lon = parseFloat(host.inventory.location_lon);
                bounds.push([lat, lon]);
            }
        });

        if (bounds.length > 0) {
            this.map.fitBounds(bounds);
        }
    }

    getSeverityIcon(severity) {
        const icons = {
            '5': 'fas fa-bomb',
            '4': 'fas fa-radiation-alt',
            '3': 'fas fa-exclamation-circle',
            '2': 'fas fa-exclamation-triangle',
            '1': 'fas fa-info-circle',
            '0': 'fas fa-question-circle'
        };
        return icons[severity] || 'fas fa-circle';
    }

    getSeverityLabel(severity) {
        const labels = {
            '5': 'Disaster',
            '4': 'High',
            '3': 'Average',
            '2': 'Warning',
            '1': 'Information',
            '0': 'Not Classified'
        };
        return labels[severity] || 'Unknown';
    }

    showError(message) {
        if (this.messages) {
            this.messages.innerHTML = `
                <div class="msg-bad">
                    Error: ${message}
                </div>
            `;
        }
    }

    static init() {
        const viewer = new LocationMapViewer();
        viewer.init();
    }
}

// Inicializa quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', LocationMapViewer.init); 