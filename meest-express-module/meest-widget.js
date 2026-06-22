/**
 * Meest Express Widget — vanilla JS
 * ===================================
 * Виджет вибору відділення / поштомату Meest Express для форми оформлення замовлення.
 *
 * Використання:
 *   const meest = new MeestWidget({
 *     container: document.getElementById('meest-root'),
 *     apiBase:   '/api/meest.php',   // шлях до meest.php
 *     onChange:  (result) => console.log(result),
 *   });
 *
 * Callback onChange отримує об'єкт:
 *   {
 *     mode:       'branch' | 'locker',
 *     city:       'Львів',
 *     cityUid:    'abc-123',
 *     branch:     'Відділення №5, вул. ...',
 *     branchUid:  'xyz-456',
 *     address:    'Львів, Відділення №5, вул. ...',   // готовий рядок
 *   }
 *   або null — якщо вибір ще не зроблений
 */

class MeestWidget {
  constructor({ container, apiBase = '/api/meest.php', onChange = () => {} }) {
    this.container = container;
    this.apiBase   = apiBase;
    this.onChange  = onChange;

    this._state = {
      mode:             'branch',   // 'branch' | 'locker'
      cityQuery:        '',
      cityUid:          '',
      cityName:         '',
      citySuggestions:  [],
      branches:         [],
      branchesLoaded:   false,
      selectedBranch:   '',
      selectedBranchName: '',
      hasData:          false,
      cityTimer:        null,
    };

    this._checkData();
    this._render();
  }

  // ── API helpers ──────────────────────────────────────────────────────────

  async _checkData() {
    try {
      const r = await fetch(`${this.apiBase}?action=status`).then(r => r.json());
      const hasData = (r.cities || 0) > 0;
      this._state.hasData = hasData;
      this._render();
    } catch {}
  }

  async _fetchCities(q) {
    try {
      const data = await fetch(`${this.apiBase}?action=cities&q=${encodeURIComponent(q)}&limit=8`).then(r => r.json());
      this._state.citySuggestions = Array.isArray(data) ? data : [];
      this._render();
    } catch {}
  }

  async _fetchBranches(cityUid, mode) {
    this._state.branches       = [];
    this._state.branchesLoaded = false;
    this._state.selectedBranch = '';
    this._state.selectedBranchName = '';
    this._render();
    try {
      const url = `${this.apiBase}?action=branches&city_uid=${encodeURIComponent(cityUid)}` + (mode === 'locker' ? '&locker=1' : '');
      const data = await fetch(url).then(r => r.json());
      this._state.branches       = Array.isArray(data) ? data : [];
      this._state.branchesLoaded = true;
      this._render();
    } catch {
      this._state.branchesLoaded = true;
      this._render();
    }
  }

  // ── Render ───────────────────────────────────────────────────────────────

  _render() {
    const s = this._state;
    const label    = s.mode === 'locker' ? 'Поштомат' : 'Відділення';
    const labelLow = s.mode === 'locker' ? 'поштоматів' : 'відділень';
    const placeholder = s.mode === 'locker' ? 'Оберіть поштомат...' : 'Оберіть відділення...';

    this.container.innerHTML = `
      <div class="meest-widget" style="display:flex;flex-direction:column;gap:10px;font-family:inherit">

        <!-- Mode selector -->
        <div style="display:flex;border:1px solid rgba(0,0,0,.18);overflow:hidden">
          <button data-meest-mode="branch"
            style="flex:1;padding:9px 4px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:600;border:none;cursor:pointer;
                   background:${s.mode==='branch'?'#28332c':'#fff'};
                   color:${s.mode==='branch'?'#f1ebe1':'#6c7166'};
                   transition:all .25s">
            Відділення
          </button>
          <button data-meest-mode="locker"
            style="flex:1;padding:9px 4px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:600;border:none;border-left:1px solid rgba(0,0,0,.18);cursor:pointer;
                   background:${s.mode==='locker'?'#28332c':'#fff'};
                   color:${s.mode==='locker'?'#f1ebe1':'#6c7166'};
                   transition:all .25s">
            Поштомат
          </button>
        </div>

        ${!s.hasData ? `
          <div style="background:#fff8f0;border:1px solid rgba(167,97,63,.3);padding:12px;font-size:12.5px;color:#a7613f;line-height:1.5">
            Дані відділень Meest не завантажені.<br>
            Завантажте ZIP-архів через адмінку (вкладка Meest).
          </div>
        ` : `
          <!-- City input -->
          <div style="position:relative">
            <label style="display:block">
              <span style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6c7166;display:block;margin-bottom:4px">Місто</span>
              <input data-meest="city-input" type="text" value="${this._esc(s.cityQuery)}" placeholder="Почніть вводити місто..."
                style="width:100%;box-sizing:border-box;background:#fff;border:1px solid rgba(0,0,0,.2);padding:10px 12px;font-size:13px;color:#1e231f;outline:none;font-family:inherit"/>
            </label>
            ${s.citySuggestions.length ? `
              <div style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid rgba(0,0,0,.12);border-top:none;z-index:50;max-height:220px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.1)">
                ${s.citySuggestions.map(c => `
                  <div data-meest-city-uid="${this._esc(c.uid)}" data-meest-city-name="${this._esc(c.name)}"
                    style="padding:10px 14px;font-size:13px;cursor:pointer;border-bottom:1px solid rgba(0,0,0,.06)"
                    onmouseover="this.style.background='#f5f5f2'" onmouseout="this.style.background=''">
                    ${this._esc(c.name)}
                    <span style="font-size:11px;color:#999;margin-left:6px">${this._esc(c.type || '')}</span>
                  </div>
                `).join('')}
              </div>
            ` : ''}
          </div>

          <!-- Branches select -->
          ${s.cityUid && s.branchesLoaded ? (
            s.branches.length ? `
              <label style="display:block">
                <span style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6c7166;display:block;margin-bottom:4px">
                  ${label} <span style="color:#aaa;font-size:10px">(${s.branches.length} шт.)</span>
                </span>
                <select data-meest="branch-select"
                  style="width:100%;background:#fff;border:1px solid rgba(0,0,0,.2);padding:10px 12px;font-size:12px;color:#1e231f;outline:none;font-family:inherit;cursor:pointer">
                  <option value="">${placeholder}</option>
                  ${s.branches.map(b => `
                    <option value="${this._esc(b.uid)}" ${s.selectedBranch===b.uid?'selected':''}>${this._esc(b.name)} — ${this._esc(b.address)}</option>
                  `).join('')}
                </select>
              </label>
            ` : `
              <div style="background:#fff8f0;border:1px solid rgba(167,97,63,.25);padding:12px;font-size:12.5px;color:#a7613f;line-height:1.5">
                В обраному місті немає ${labelLow} Meest. Спробуйте інший тип або місто.
              </div>
            `
          ) : s.cityUid && !s.branchesLoaded ? `
            <div style="font-size:12px;color:#999;padding:8px 0">Завантаження відділень...</div>
          ` : ''}
        `}

      </div>
    `;

    this._bind();
  }

  _bind() {
    const s = this._state;

    // Mode buttons
    this.container.querySelectorAll('[data-meest-mode]').forEach(btn => {
      btn.addEventListener('click', () => {
        const mode = btn.dataset.meestMode;
        if (mode === s.mode) return;
        s.mode           = mode;
        s.selectedBranch = '';
        s.selectedBranchName = '';
        s.branches       = [];
        s.branchesLoaded = false;
        if (s.cityUid) this._fetchBranches(s.cityUid, mode);
        else this._render();
      });
    });

    // City input
    const cityInput = this.container.querySelector('[data-meest="city-input"]');
    if (cityInput) {
      cityInput.addEventListener('input', e => {
        s.cityQuery = e.target.value;
        s.cityUid   = '';
        s.branches  = [];
        s.branchesLoaded = false;
        s.selectedBranch = '';
        clearTimeout(s.cityTimer);
        if (s.cityQuery.length >= 2) {
          s.cityTimer = setTimeout(() => this._fetchCities(s.cityQuery), 300);
        } else {
          s.citySuggestions = [];
          this._render();
        }
        this._emit(null);
      });
    }

    // City suggestions
    this.container.querySelectorAll('[data-meest-city-uid]').forEach(el => {
      el.addEventListener('click', () => {
        s.cityUid          = el.dataset.meestCityUid;
        s.cityName         = el.dataset.meestCityName;
        s.cityQuery        = el.dataset.meestCityName;
        s.citySuggestions  = [];
        s.selectedBranch   = '';
        s.selectedBranchName = '';
        this._fetchBranches(s.cityUid, s.mode);
      });
    });

    // Branch select
    const branchSelect = this.container.querySelector('[data-meest="branch-select"]');
    if (branchSelect) {
      branchSelect.addEventListener('change', e => {
        s.selectedBranch = e.target.value;
        const found = s.branches.find(b => b.uid === s.selectedBranch);
        s.selectedBranchName = found ? `${found.name} — ${found.address}` : '';
        this._render();
        this._emit(s.selectedBranch ? {
          mode:       s.mode,
          city:       s.cityName,
          cityUid:    s.cityUid,
          branch:     s.selectedBranchName,
          branchUid:  s.selectedBranch,
          address:    `${s.cityName}, ${s.selectedBranchName}`,
        } : null);
      });
    }
  }

  _emit(result) {
    this.onChange(result);
  }

  _esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Public API ───────────────────────────────────────────────────────────

  /** Отримати поточний вибір або null */
  getValue() {
    const s = this._state;
    if (!s.cityUid || !s.selectedBranch) return null;
    return {
      mode:       s.mode,
      city:       s.cityName,
      cityUid:    s.cityUid,
      branch:     s.selectedBranchName,
      branchUid:  s.selectedBranch,
      address:    `${s.cityName}, ${s.selectedBranchName}`,
    };
  }

  /** Скинути вибір */
  reset() {
    this._state = { ...this._state, cityQuery:'', cityUid:'', cityName:'', citySuggestions:[], branches:[], branchesLoaded:false, selectedBranch:'', selectedBranchName:'' };
    this._render();
    this._emit(null);
  }
}

// CommonJS / ESM export
if (typeof module !== 'undefined') module.exports = MeestWidget;
if (typeof window !== 'undefined') window.MeestWidget = MeestWidget;
