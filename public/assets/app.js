/* LANDLORDS map page.
   Loads the daily data files written by bin/sync.php and shows them on a Leaflet map. */
(function () {
  'use strict';

  var LAYERS = [
    { id: 'hmo',           label: 'HMO licences' },
    { id: 'hmo-app',       label: 'HMO applications' },
    { id: 'selective',     label: 'Other licensed rented homes' },
    { id: 'selective-app', label: 'Selective licence applications' },
    { id: 'student',       label: 'Student accommodation' }
  ];
  var LIST_PAGE = 100;
  var NEW_DAYS = 7;
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var MOBILE = window.matchMedia('(max-width: 760px)');
  var TODAY = new Date().toISOString().slice(0, 10);

  var state = {
    groups: [],               // one per pin: properties of one layer at one spot (a house, or a block of flats)
    byId: new Map(),          // property id -> its group
    shown: [],                // groups passing the filters
    layers: new Set(LAYERS.map(function (l) { return l.id; })),
    nearby: true,
    margin: 0,
    terms: [],
    pinned: null,             // id from ?p=, always shown whatever the filters
    active: null,             // id whose popup is open
    listLimit: LIST_PAGE
  };

  var el = {
    fresh: document.getElementById('fresh'),
    notice: document.getElementById('notice'),
    q: document.getElementById('q'),
    layers: document.getElementById('layers'),
    nearby: document.getElementById('nearby'),
    nearbyToggle: document.getElementById('nearby-toggle'),
    nearbyLabel: document.getElementById('nearby-label'),
    nearbyCount: document.getElementById('nearby-count'),
    areaNote: document.getElementById('area-note'),
    heading: document.getElementById('results-heading'),
    list: document.getElementById('list'),
    more: document.getElementById('more'),
    mapWrap: document.querySelector('.map-wrap')
  };

  // ---------- Small helpers ----------

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function formatDate(iso) {
    if (!iso) return '';
    return Number(iso.slice(8, 10)) + ' ' + MONTHS[Number(iso.slice(5, 7)) - 1] + ' ' + iso.slice(0, 4);
  }

  function isoDaysAgo(days) {
    var d = new Date();
    d.setDate(d.getDate() - days);
    return d.toISOString().slice(0, 10);
  }

  function monthsBetween(fromIso, toIso) {
    var a = new Date(fromIso + 'T12:00:00Z');
    var b = new Date(toIso + 'T12:00:00Z');
    return (b - a) / (30.44 * 86400000);
  }

  // "1 Clumber Road East, Nottingham, Nottingham City, NG7 1BD" -> "1 Clumber Road East, NG7 1BD"
  function tidyAddress(address) {
    if (!address) return '';
    return address.split(',')
      .map(function (part) { return part.replace(/\s+/g, ' ').trim(); })
      .filter(function (part) { return part && !/^nottingham( city)?$/i.test(part); })
      .join(', ');
  }

  function withoutPostcode(address) {
    return tidyAddress(address).split(', ').filter(function (part) { return !/^NG\d/i.test(part); }).join(', ');
  }

  // For grouping flats: the part of each address the units share. When the flats are
  // numbered within a named building ("4 Westcliffe Court, 45-47 Lenton Road"), keep
  // the building name too: "Westcliffe Court, 45-47 Lenton Road".
  function commonTail(addresses) {
    var split = addresses.map(function (a) { return withoutPostcode(a).split(', '); });
    var tail = [];
    for (var i = 1; ; i++) {
      var part = split[0][split[0].length - i];
      if (part === undefined) break;
      if (!split.every(function (s) { return s[s.length - i] === part; })) {
        var unitless = function (s) {
          var p = s[s.length - i];
          return p === undefined ? null : p.replace(/^(flat|apartment|room|unit)?\s*[0-9]+[a-z]?\s+/i, '');
        };
        var building = unitless(split[0]);
        if (building && /[a-z]/i.test(building) && !/^\d/.test(building) &&
            split.every(function (s) { return unitless(s) === building; })) {
          tail.unshift(building);
        }
        break;
      }
      tail.unshift(part);
    }
    return tail.join(', ');
  }

  // A sort key that puts "2 Lenton Road" before "10 Lenton Road" and groups by street.
  function streetKey(address) {
    var a = withoutPostcode(address).toLowerCase().replace(/^(flat|apartment|room|unit)\s+\S+,\s*/, '');
    var m = a.match(/^(\d+)\s*([a-z]?)\b[\s-]*(.*)$/);
    if (!m) return a + ' 00000';
    return m[3] + ' ' + ('00000' + m[1]).slice(-5) + m[2];
  }

  function oneLine(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
  }

  function shareUrl(id) {
    return location.origin + location.pathname + '?p=' + encodeURIComponent(id);
  }

  function plural(n, one, many) {
    return n + ' ' + (n === 1 ? one : (many || one + 's'));
  }

  // "HMO licence (additional)" -> "3 HMO licences (additional)"
  function pluralLabel(n, label) {
    return n + ' ' + (n === 1 ? label : label.replace(/\b(licence|application)\b(?!.*\b(licence|application)\b)/, '$1s'));
  }

  // ---------- Map ----------

  var map = L.map('map', { minZoom: 12, maxZoom: 19, zoomSnap: 0.5, keyboard: true });
  map.attributionControl.setPrefix(false);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · Licensing data: Nottingham City Council'
  }).addTo(map);
  map.setView([52.9515, -1.1625], 16);

  var studentLayer = L.featureGroup().addTo(map);

  var cluster = L.markerClusterGroup({
    maxClusterRadius: 34,
    disableClusteringAtZoom: 18,
    showCoverageOnHover: false,
    spiderfyOnMaxZoom: true,
    chunkedLoading: true,
    iconCreateFunction: function (c) {
      var n = 0;
      var hmo = 0;
      c.getAllChildMarkers().forEach(function (m) {
        n += m.options.units;
        if (m.options.layer === 'hmo' || m.options.layer === 'hmo-app') hmo += m.options.units;
      });
      var size = n < 10 ? 32 : n < 100 ? 38 : 46;
      return L.divIcon({
        html: '<div>' + n + '</div>' + (hmo ? '<b title="HMOs">' + hmo + '</b>' : ''),
        className: 'cluster' + (hmo ? ' has-hmo' : ''),
        iconSize: [size, size]
      });
    }
  });
  map.addLayer(cluster);

  var parkBounds = null;
  var fitted = false;

  function fitPark() {
    var size = map.getSize();
    if (!parkBounds || size.x < 80 || size.y < 80) return false;
    map.fitBounds(parkBounds, { padding: [24, 24] });
    fitted = true;
    return true;
  }

  if (window.ResizeObserver) {
    new ResizeObserver(function () {
      map.invalidateSize({ pan: false });
      if (!fitted && fitPark()) {
        var id = state.active || state.pinned;
        if (id) openProperty(id, false);
      }
    }).observe(document.getElementById('map'));
  }

  // The conservation area is dashed; the wider area (the margin around it) is solid,
  // and a pale veil softens everything beyond what the site covers.
  function drawBoundary(geojson, watchArea) {
    var outline = L.geoJSON(geojson, {
      interactive: false,
      style: { color: '#1f3d34', weight: 2.5, opacity: 0.9, dashArray: '7 5', fill: false }
    }).addTo(map);
    var outer = outline;
    if (watchArea) {
      outer = L.geoJSON(watchArea, {
        interactive: false,
        style: { color: '#1f3d34', weight: 1.5, opacity: 0.75, fill: false }
      }).addTo(map);
    }
    var holes = [];
    (watchArea || geojson).features.forEach(function (f) {
      var polys = f.geometry.type === 'MultiPolygon' ? f.geometry.coordinates : [f.geometry.coordinates];
      polys.forEach(function (rings) {
        holes.push(rings[0].map(function (c) { return [c[1], c[0]]; }));
      });
    });
    L.polygon([[[85, -180], [85, 180], [-85, 180], [-85, -180]]].concat(holes), {
      stroke: false, fillColor: '#f2f3ef', fillOpacity: 0.5, interactive: false, className: 'outside'
    }).addTo(map);
    outline.bringToFront();
    outer.bringToFront();

    parkBounds = outer.getBounds();
    map.setMaxBounds(parkBounds.pad(1.5));
    fitPark();
  }

  // ---------- Properties ----------

  function buildLicences(geojson) {
    var newSince = isoDaysAgo(NEW_DAYS);
    var byKey = new Map();
    geojson.features.forEach(function (f) {
      var p = f.properties;
      var c = f.geometry && f.geometry.coordinates;
      if (!c) return;
      var unit = {
        id: p.id, layer: p.layer, ref: p.ref, scheme: p.scheme, label: p.label,
        address: p.address, appDate: p.app_date, issued: p.issued, expires: p.expires,
        occupants: p.occupants, households: p.households, renewal: p.renewal,
        propertyType: p.property_type, url: p.url, inside: p.inside !== false,
        isNew: !!(p.first_seen && p.first_seen >= newSince)
      };
      var key = p.layer + '|' + c[1].toFixed(5) + '|' + c[0].toFixed(5);
      var group = byKey.get(key);
      if (!group) {
        group = { id: p.id, layer: p.layer, units: [], latlng: L.latLng(c[1], c[0]), inside: unit.inside };
        byKey.set(key, group);
        state.groups.push(group);
      }
      group.units.push(unit);
      state.byId.set(unit.id, group);
    });
    byKey.forEach(function (g) {
      g.units.sort(function (a, b) { return streetKey(a.address) < streetKey(b.address) ? -1 : 1; });
      g.id = g.units[0].id;
      g.isNew = g.units.some(function (u) { return u.isNew; });
      g.address = g.units.length === 1 ? tidyAddress(g.units[0].address)
        : (commonTail(g.units.map(function (u) { return u.address; })) || withoutPostcode(g.units[0].address));
      g.sortKey = streetKey(g.units.length === 1 ? g.units[0].address : g.address);
      g.search = g.units.map(function (u) { return [u.address, u.ref, u.label, u.propertyType].join(' '); }).join(' ').toLowerCase();
      g.marker = makeMarker(g);
    });
  }

  function buildStudents(geojson) {
    var newSince = isoDaysAgo(NEW_DAYS);
    geojson.features.forEach(function (f) {
      var p = f.properties;
      var shape = L.geoJSON(f, {
        style: { color: '#7a5200', weight: 2, fillColor: '#e3a72f', fillOpacity: 0.45 }
      });
      var centre = p.centre ? L.latLng(p.centre[1], p.centre[0]) : shape.getBounds().getCenter();
      var group = {
        id: p.id, layer: 'student', student: p, units: [], shape: shape, latlng: centre,
        inside: p.inside !== false, isNew: !!(p.first_seen && p.first_seen >= newSince),
        address: p.name && (p.address || '').toLowerCase().indexOf(p.name.toLowerCase()) === -1
          ? p.name + ', ' + tidyAddress(p.address) : tidyAddress(p.address || p.name)
      };
      group.sortKey = streetKey(p.address || p.name || '');
      group.search = [p.name, p.address, p.provider, p.planning_ref, 'student'].join(' ').toLowerCase();
      shape.bindPopup(function () { return studentCard(group); }, popupOptions());
      shape.on('popupopen', function () { setActive(group.id); });
      shape.on('popupclose', function () { if (state.active === group.id) setActive(null); });
      group.marker = shape;
      state.groups.push(group);
      state.byId.set(group.id, group);
    });
  }

  function popupOptions() {
    return { maxWidth: 420, autoPanPaddingTopLeft: [20, 60], autoPanPaddingBottomRight: [20, 20] };
  }

  function makeMarker(group) {
    var n = group.units.length;
    var marker = L.marker(group.latlng, {
      icon: L.divIcon({
        className: 'pin layer-' + group.layer + (group.isNew ? ' is-new' : '') + (n > 1 ? ' is-group' : ''),
        html: '<span>' + (n > 1 ? n : '') + '</span>',
        iconSize: n > 1 ? [24, 24] : [16, 16]
      }),
      title: group.address,
      alt: group.address,
      keyboard: true,
      riseOnHover: true,
      layer: group.layer,
      units: n
    });
    marker.bindPopup(function () { return licenceCard(group); }, popupOptions());
    marker.on('popupopen', function () { setActive(state.byId.get(state.active) === group ? state.active : group.id); });
    marker.on('popupclose', function () { if (state.byId.get(state.active) === group) setActive(null); });
    return marker;
  }

  function expiryText(iso) {
    if (!iso) return '';
    if (iso < TODAY) return formatDate(iso) + ' <span class="warn">(passed)</span>';
    var months = monthsBetween(TODAY, iso);
    return formatDate(iso) + (months < 3 ? ' <span class="warn">(soon)</span>' : '');
  }

  function unitFacts(u) {
    var rows = '';
    if (u.layer === 'hmo-app' || u.layer === 'selective-app') {
      if (u.appDate) rows += '<dt>Applied</dt><dd>' + formatDate(u.appDate) + '</dd>';
    } else {
      if (u.issued) rows += '<dt>Issued</dt><dd>' + formatDate(u.issued) + '</dd>';
      if (u.expires) rows += '<dt>Expires</dt><dd>' + expiryText(u.expires) + '</dd>';
      if (u.occupants) rows += '<dt>Up to</dt><dd>' + plural(u.occupants, 'person', 'people') +
        (u.households ? ' in ' + plural(u.households, 'household') : '') + '</dd>';
      if (u.renewal != null) rows += '<dt>Licence</dt><dd>' + (u.renewal ? 'Renewal' : 'First licence at this address') + '</dd>';
      if (u.propertyType) rows += '<dt>Property</dt><dd>' + esc(u.propertyType.replace(/^Non HMO /, '')) + '</dd>';
    }
    rows += '<dt>Reference</dt><dd>' + esc(u.ref) + '</dd>';
    return '<dl class="facts">' + rows + '</dl>';
  }

  function whereNote(group) {
    return group.inside ? '' :
      '<p class="card-where">Just outside The Park, within ' + state.margin + ' m of the conservation area</p>';
  }

  function licenceCard(group) {
    var u = group.units[0];
    var n = group.units.length;
    var head = '<h3 class="card-address">' + esc(group.address || 'Address not given') + '</h3>';
    var actions = '<div class="card-actions">' +
      (u.url ? '<a class="btn-council" href="' + esc(u.url) + '" target="_blank" rel="noopener">About this licence<span class="sr-only"> (council website, opens in a new tab)</span></a>' : '') +
      '<button type="button" class="btn-copy" data-id="' + esc(state.byId.get(state.active) === group ? state.active : group.id) + '">Copy link</button>' +
      '</div>';
    if (n === 1) {
      return '<article class="card">' + head +
        '<p class="card-type"><span class="swatch layer-' + group.layer + '"></span>' + esc(u.label) +
          (u.isNew ? '<span class="badge-new">New this week</span>' : '') + '</p>' + whereNote(group) +
        unitFacts(u) + actions + '</article>';
    }
    var units = group.units.map(function (unit) {
      var own = withoutPostcode(unit.address);
      if (group.address && own.length > group.address.length && own.slice(-group.address.length) === group.address) {
        own = own.slice(0, -group.address.length).replace(/[\s,]+$/, '');
      }
      if (/^[0-9]+[a-z]?$/i.test(own)) own = 'Flat ' + own;
      return '<li class="unit' + (unit.id === state.active ? ' is-active' : '') + '" data-id="' + esc(unit.id) + '">' +
        '<details' + (unit.id === state.active ? ' open' : '') + '><summary><span class="unit-name">' + esc(own) + '</span>' +
          (unit.isNew ? '<span class="badge-new">New</span>' : '') +
          '<span class="unit-sub">' + (unit.expires ? 'to ' + formatDate(unit.expires) : unit.appDate ? 'applied ' + formatDate(unit.appDate) : '') + '</span></summary>' +
        unitFacts(unit) + '</details></li>';
    }).join('');
    return '<article class="card">' + head +
      '<p class="card-type"><span class="swatch layer-' + group.layer + '"></span>' + esc(pluralLabel(n, u.label)) + ' at this address</p>' +
      whereNote(group) + '<ul class="units">' + units + '</ul>' + actions + '</article>';
  }

  function studentCard(group) {
    var p = group.student;
    var rows = '';
    if (p.bedspaces) {
      var mix = [];
      if (p.studios) mix.push(plural(p.studios, 'studio'));
      if (p.clusters) mix.push(plural(p.clusters, 'room') + ' in cluster flats');
      rows += '<dt>Bed spaces</dt><dd>' + p.bedspaces + (mix.length ? ' (' + mix.join(', ') + ')' : '') + '</dd>';
    }
    if (p.scheme_type) rows += '<dt>Type</dt><dd>' + esc(p.scheme_type) + '</dd>';
    if (p.provider && !/^unknown$/i.test(p.provider)) rows += '<dt>Run by</dt><dd>' + esc(p.provider) + '</dd>';
    if (p.year) {
      var year = /^archive$/i.test(p.year) ? 'Earlier (the council lists it as “Archive”)' : p.year;
      var future = /^\d{4}\/\d{2}$/.test(p.year) && Number(p.year.slice(0, 4)) >= new Date().getFullYear();
      rows += '<dt>' + (future ? 'Expected' : 'Opened') + '</dt><dd>' + esc(year) + '</dd>';
    }
    if (p.planning_ref) {
      rows += '<dt>Planning</dt><dd>' + (p.planning_url
        ? '<a href="' + esc(p.planning_url) + '" target="_blank" rel="noopener">' + esc(p.planning_ref) + '<span class="sr-only"> (on NIMBY, opens in a new tab)</span></a>'
        : esc(p.planning_ref)) + '</dd>';
    }
    return '<article class="card">' +
      '<h3 class="card-address">' + esc(p.name || tidyAddress(p.address)) + '</h3>' +
      '<p class="card-type"><span class="swatch layer-student"></span>Purpose-built student accommodation' +
        (group.isNew ? '<span class="badge-new">New this week</span>' : '') + '</p>' +
      (p.address && p.address !== p.name ? '<p class="card-sub">' + esc(tidyAddress(p.address)) + '</p>' : '') +
      whereNote(group) +
      '<dl class="facts">' + rows + '</dl>' +
      '<div class="card-actions"><button type="button" class="btn-copy" data-id="' + esc(group.id) + '">Copy link</button></div>' +
    '</article>';
  }

  function setActive(id) {
    var previous = state.active && state.byId.get(state.active);
    if (previous && previous.marker.getElement && previous.marker.getElement()) previous.marker.getElement().classList.remove('is-active');
    state.active = id;

    var group = id && state.byId.get(id);
    if (group && group.marker.getElement && group.marker.getElement()) group.marker.getElement().classList.add('is-active');

    var params = new URLSearchParams(location.search);
    if (id) params.set('p', id); else params.delete('p');
    var query = params.toString();
    history.replaceState(null, '', location.pathname + (query ? '?' + query : ''));

    Array.prototype.forEach.call(el.list.querySelectorAll('.item'), function (b) {
      b.setAttribute('aria-current', group && b.dataset.id === group.id ? 'true' : 'false');
    });
  }

  function openProperty(id, fromList) {
    var group = state.byId.get(id);
    if (!group) return false;
    if (fromList && MOBILE.matches) el.mapWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    state.active = id;
    if (group.layer === 'student') {
      map.fitBounds(group.shape.getBounds(), { maxZoom: 18, padding: [40, 40] });
      group.shape.openPopup(group.latlng);
    } else {
      cluster.zoomToShowLayer(group.marker, function () { group.marker.openPopup(); });
    }
    return true;
  }

  // ---------- Filtering ----------

  function passes(group, ignore) {
    if (state.pinned && state.byId.get(state.pinned) === group) return true;
    if (ignore !== 'nearby' && !state.nearby && !group.inside) return false;
    for (var i = 0; i < state.terms.length; i++) {
      if (group.search.indexOf(state.terms[i]) === -1) return false;
    }
    if (ignore !== 'layer' && !state.layers.has(group.layer)) return false;
    return true;
  }

  function readFilters() {
    state.nearby = el.nearby.checked;
    state.terms = el.q.value.toLowerCase().split(/\s+/).filter(Boolean);
    state.layers = new Set(Array.prototype.map.call(el.layers.querySelectorAll('input:checked'), function (i) { return i.value; }));
  }

  function units(group) {
    return group.layer === 'student' ? 1 : group.units.length;
  }

  function apply() {
    readFilters();

    var counts = {};
    LAYERS.forEach(function (l) { counts[l.id] = 0; });
    var nearbyCount = 0;
    state.groups.forEach(function (g) {
      if (passes(g, 'layer')) counts[g.layer] += units(g);
      if (!g.inside && passes(g, 'nearby')) nearbyCount += units(g);
    });
    LAYERS.forEach(function (l) {
      var node = el.layers.querySelector('[data-count="' + l.id + '"]');
      if (node) node.textContent = counts[l.id];
    });
    el.nearbyCount.textContent = '(' + nearbyCount + ')';

    state.shown = state.groups.filter(function (g) { return passes(g); });
    cluster.clearLayers();
    studentLayer.clearLayers();
    cluster.addLayers(state.shown.filter(function (g) { return g.layer !== 'student'; }).map(function (g) { return g.marker; }));
    state.shown.filter(function (g) { return g.layer === 'student'; }).forEach(function (g) { studentLayer.addLayer(g.shape); });

    state.listLimit = LIST_PAGE;
    renderList();
  }

  function renderLayers() {
    el.layers.innerHTML = LAYERS.map(function (l) {
      return '<label class="layer-option"><input type="checkbox" value="' + l.id + '" checked>' +
        '<span class="swatch layer-' + l.id + '"></span><span class="layer-name">' + esc(l.label) + '</span>' +
        ' <span class="count" data-count="' + l.id + '"></span></label>';
    }).join('');
  }

  // ---------- List ----------

  function describe(group) {
    if (group.layer === 'student') {
      var p = group.student;
      return 'Student accommodation' + (p.bedspaces ? ', ' + p.bedspaces + ' bed spaces' : '');
    }
    var u = group.units[0];
    if (group.units.length > 1) return pluralLabel(group.units.length, u.label);
    if (u.expires) return u.label + ', to ' + formatDate(u.expires);
    if (u.appDate) return u.label + ', applied ' + formatDate(u.appDate);
    return u.label;
  }

  function renderList() {
    var bounds = map.getBounds();
    var inView = state.shown.filter(function (g) { return bounds.contains(g.latlng); })
      .sort(function (a, b) { return a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0; });

    var total = state.shown.length;
    var heading;
    if (total === 0) {
      heading = 'Nothing matches';
    } else if (inView.length === total) {
      heading = plural(total, 'address', 'addresses');
    } else {
      heading = inView.length.toLocaleString('en-GB') + ' of ' + plural(total, 'address', 'addresses') +
        ' <span class="sub">in this part of the map</span>';
    }
    el.heading.innerHTML = heading;

    var focusedId = document.activeElement && document.activeElement.classList.contains('item')
      ? document.activeElement.dataset.id : null;

    if (total === 0) {
      el.list.innerHTML = '<li class="empty"><strong>Nothing matches.</strong> Tick more of the boxes above, or clear the search.</li>';
      el.more.hidden = true;
      return;
    }
    if (inView.length === 0) {
      el.list.innerHTML = '<li class="empty">None of the matching properties are in this part of the map. Zoom out to see them.</li>';
      el.more.hidden = true;
      return;
    }

    var activeGroup = state.active && state.byId.get(state.active);
    el.list.innerHTML = inView.slice(0, state.listLimit).map(function (g) {
      return '<li><button type="button" class="item" data-id="' + esc(g.id) + '" aria-current="' + (g === activeGroup) + '">' +
        '<span class="item-top"><span class="item-address">' + esc(withoutPostcode(g.address) || 'Address not given') +
          (g.isNew ? '<span class="badge-new">New</span>' : '') +
          (g.inside ? '' : '<span class="badge-nearby">Nearby</span>') + '</span></span>' +
        '<span class="item-meta"><span class="swatch layer-' + g.layer + '"></span>' + esc(describe(g)) + '</span>' +
      '</button></li>';
    }).join('');

    var remaining = inView.length - state.listLimit;
    el.more.hidden = remaining <= 0;
    if (remaining > 0) el.more.textContent = 'Show ' + Math.min(remaining, LIST_PAGE) + ' more of ' + remaining.toLocaleString('en-GB');

    if (focusedId) {
      var again = el.list.querySelector('.item[data-id="' + focusedId + '"]');
      if (again) again.focus({ preventScroll: true });
    }
  }

  // ---------- Events ----------

  var searchTimer;
  el.q.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(apply, 180);
  });
  el.nearby.addEventListener('change', apply);
  el.layers.addEventListener('change', apply);
  document.getElementById('filters').addEventListener('submit', function (e) { e.preventDefault(); apply(); });

  el.list.addEventListener('click', function (e) {
    var button = e.target.closest('.item');
    if (button) openProperty(button.dataset.id, true);
  });
  el.more.addEventListener('click', function () {
    state.listLimit += LIST_PAGE;
    renderList();
  });

  var listTimer;
  map.on('moveend', function () {
    clearTimeout(listTimer);
    listTimer = setTimeout(function () { state.listLimit = LIST_PAGE; renderList(); }, 120);
  });

  // Opening one flat in a block makes it the one the link points to.
  document.getElementById('map').addEventListener('toggle', function (e) {
    var li = e.target.closest && e.target.closest('.unit');
    if (li && e.target.open) {
      setActive(li.dataset.id);
      var copy = li.closest('.card').querySelector('.btn-copy');
      if (copy) { copy.dataset.id = li.dataset.id; copy.textContent = 'Copy link'; }
    }
  }, true);

  document.getElementById('map').addEventListener('click', function (e) {
    var button = e.target.closest('.btn-copy');
    if (!button) return;
    var url = shareUrl(button.dataset.id);
    var done = function () { button.textContent = 'Link copied'; };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url).then(done, function () { fallbackCopy(url, done); });
    } else {
      fallbackCopy(url, done);
    }
  });

  function fallbackCopy(text, done) {
    var input = document.createElement('input');
    input.value = text;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    try { if (document.execCommand('copy')) done(); } catch (err) { /* leave the button as it was */ }
    document.body.removeChild(input);
  }

  function showNotice(text) {
    el.notice.textContent = text;
    el.notice.hidden = false;
  }

  // ---------- Weekly email sign-up ----------

  function setUpSignup() {
    var section = document.getElementById('signup');
    var form = document.getElementById('signup-form');
    var result = document.getElementById('signup-result');
    if (!section || !form) return;

    fetch('subscribe.php?status', { headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : { open: false }; })
      .then(function (s) {
        if (!s.open) return;
        section.hidden = false;
        document.getElementById('masthead-signup').hidden = false;
      })
      .catch(function () { /* PHP unavailable: leave the form hidden */ });

    form.addEventListener('input', function () {
      if (result.classList.contains('is-error')) {
        result.textContent = '';
        result.className = 'signup-result';
      }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = form.elements.email.value.trim();
      result.className = 'signup-result';
      if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
        result.textContent = 'Enter a valid email address, like name@example.com.';
        result.classList.add('is-error');
        form.elements.email.focus();
        return;
      }
      if (!form.elements.consent.checked) {
        result.textContent = 'Tick the box to agree to receive the email.';
        result.classList.add('is-error');
        form.elements.consent.focus();
        return;
      }
      var button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      button.textContent = 'Signing up…';
      fetch('subscribe.php', { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          result.textContent = data.message;
          if (data.ok) {
            result.classList.add('is-ok');
            Array.prototype.forEach.call(form.querySelectorAll('.field, .consent, button'), function (n) { n.hidden = true; });
          } else {
            result.classList.add('is-error');
          }
        })
        .catch(function () {
          result.textContent = 'Sign-up didn’t go through. Check your connection and try again.';
          result.classList.add('is-error');
        })
        .then(function () {
          button.disabled = false;
          button.textContent = 'Sign up';
        });
    });
  }

  setUpSignup();

  // ---------- Start ----------

  function getJson(path) {
    return fetch(path, { cache: 'no-cache' }).then(function (r) {
      if (!r.ok) throw new Error(path + ' returned ' + r.status);
      return r.json();
    });
  }

  renderLayers();

  var requested = new URLSearchParams(location.search).get('p');
  if (requested) state.pinned = requested.toLowerCase().replace(/[^a-z0-9-]/g, '');

  var watchArea = getJson('data/watch-area.geojson').catch(function () { return null; });
  var students = getJson('data/students.geojson').catch(function () { return { features: [] }; });

  Promise.all([getJson('data/licences.geojson'), getJson('data/park-boundary.geojson'), getJson('data/meta.json'), watchArea, students])
    .then(function (results) {
      var meta = results[2];
      state.margin = Number(meta.buffer_metres) || 0;
      drawBoundary(results[1], state.margin ? results[3] : null);
      buildLicences(results[0]);
      buildStudents(results[4]);
      if (state.margin) {
        el.nearbyLabel.textContent = 'Include neighbouring properties within ' + state.margin + ' m';
        el.nearbyToggle.hidden = false;
        el.areaNote.textContent = 'The dashed line is The Park Conservation Area. The solid line takes in properties within ' +
          state.margin + ' m of it.';
        el.areaNote.hidden = false;
      }

      var synced = new Date(meta.synced_at.replace(' ', 'T'));
      el.fresh.textContent = 'Council data checked ' + synced.toLocaleString('en-GB', {
        day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London'
      }).replace(',', ' at');

      apply();

      if (state.pinned) {
        if (!state.byId.has(state.pinned)) {
          showNotice('That property isn’t on the map any more. Its licence may have ended, or the link may be incomplete.');
          state.pinned = null;
          setActive(null);
        } else if (fitted) {
          openProperty(state.pinned, false);
        }
      }
    })
    .catch(function (err) {
      el.fresh.textContent = 'The licensing data couldn’t be loaded. Refresh the page to try again.';
      el.heading.textContent = 'Properties unavailable';
      if (window.console) console.error(err);
    });
})();
