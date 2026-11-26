// Fixed 100-900 scales defined as RGB channels in src/styles.css.
const scale = (name) => Object.fromEntries(
  [100, 200, 300, 400, 500, 600, 700, 800, 900].map((step) => [step, `rgb(var(--${name}-${step}) / <alpha-value>)`]),
)

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js}'],
  darkMode: 'selector',
  theme: {
    extend: {
      colors: {
        secondary: scale('secondary'),
        neutral: scale('neutral'),
        primary: {
          ...scale('primary'),
          DEFAULT: 'rgb(var(--primary) / <alpha-value>)',
          hover: 'rgb(var(--primary-hover) / <alpha-value>)',
          active: 'rgb(var(--primary-active) / <alpha-value>)',
          subtle: 'rgb(var(--primary-subtle) / <alpha-value>)',
          foreground: 'rgb(var(--primary-foreground) / <alpha-value>)',
        },
        accent: {
          ...scale('accent'),
          DEFAULT: 'rgb(var(--accent) / <alpha-value>)',
          hover: 'rgb(var(--accent-hover) / <alpha-value>)',
          active: 'rgb(var(--accent-active) / <alpha-value>)',
          subtle: 'rgb(var(--accent-subtle) / <alpha-value>)',
          foreground: 'rgb(var(--accent-foreground) / <alpha-value>)',
        },
        surface: {
          DEFAULT: 'rgb(var(--surface) / <alpha-value>)',
          subtle: 'rgb(var(--surface-subtle) / <alpha-value>)',
          muted: 'rgb(var(--surface-muted) / <alpha-value>)',
          raised: 'rgb(var(--surface-raised) / <alpha-value>)',
          inverse: 'rgb(var(--surface-inverse) / <alpha-value>)',
        },
        frame: 'rgb(var(--frame) / <alpha-value>)',
        ink: {
          DEFAULT: 'rgb(var(--ink) / <alpha-value>)',
          strong: 'rgb(var(--ink-strong) / <alpha-value>)',
          muted: 'rgb(var(--ink-muted) / <alpha-value>)',
          subtle: 'rgb(var(--ink-subtle) / <alpha-value>)',
          inverse: 'rgb(var(--ink-inverse) / <alpha-value>)',
        },
        border: {
          DEFAULT: 'rgb(var(--border) / <alpha-value>)',
          strong: 'rgb(var(--border-strong) / <alpha-value>)',
          subtle: 'rgb(var(--border-subtle) / <alpha-value>)',
          focus: 'rgb(var(--border-focus) / <alpha-value>)',
        },
        success: {
          DEFAULT: 'rgb(var(--success) / <alpha-value>)',
          strong: 'rgb(var(--success-strong) / <alpha-value>)',
          subtle: 'rgb(var(--success-subtle) / <alpha-value>)',
          foreground: 'rgb(var(--success-foreground) / <alpha-value>)',
          soft: 'rgb(var(--success-subtle) / <alpha-value>)',
        },
        warning: {
          50: 'rgb(var(--warning-subtle) / <alpha-value>)',
          200: 'rgb(var(--warning-subtle) / <alpha-value>)',
          DEFAULT: 'rgb(var(--warning) / <alpha-value>)',
          strong: 'rgb(var(--warning-strong) / <alpha-value>)',
          subtle: 'rgb(var(--warning-subtle) / <alpha-value>)',
          foreground: 'rgb(var(--warning-foreground) / <alpha-value>)',
          soft: 'rgb(var(--warning-subtle) / <alpha-value>)',
        },
        danger: {
          DEFAULT: 'rgb(var(--danger) / <alpha-value>)',
          strong: 'rgb(var(--danger-strong) / <alpha-value>)',
          subtle: 'rgb(var(--danger-subtle) / <alpha-value>)',
          foreground: 'rgb(var(--danger-foreground) / <alpha-value>)',
        },
        info: {
          DEFAULT: 'rgb(var(--info) / <alpha-value>)',
          strong: 'rgb(var(--info-strong) / <alpha-value>)',
          subtle: 'rgb(var(--info-subtle) / <alpha-value>)',
          foreground: 'rgb(var(--info-foreground) / <alpha-value>)',
        },
        maintenance: {
          ok: 'rgb(var(--maintenance-ok) / <alpha-value>)',
          due: 'rgb(var(--maintenance-due) / <alpha-value>)',
          overdue: 'rgb(var(--maintenance-overdue) / <alpha-value>)',
          scheduled: 'rgb(var(--maintenance-scheduled) / <alpha-value>)',
          inactive: 'rgb(var(--maintenance-inactive) / <alpha-value>)',
        },
      },
      // Shell rediseñado (docs/DESIGN_TOKENS.md, "Radios").
      borderRadius: {
        pill: '9999px',
        card: '24px',
        panel: '32px',
        frame: '40px',
      },
      boxShadow: {
        card: '0 1px 2px rgb(var(--shadow-rgb) / 0.04), 0 8px 24px rgb(var(--shadow-rgb) / 0.05)',
        sidebar: '8px 0 24px rgb(var(--shadow-rgb) / 0.16)',
      },
      // Moderate scale (docs/DESIGN_TOKENS.md): nothing below 13px. Redefining
      // xs/sm/xl here grows the ~2,500 existing usages without touching them.
      fontSize: {
        xs: ['0.8125rem', { lineHeight: '1.125rem' }],
        sm: ['0.9375rem', { lineHeight: '1.375rem' }],
        xl: ['1.375rem', { lineHeight: '1.875rem' }],
      },
      fontFamily: {
        // Self-hosted through @fontsource-variable/inter (imported in main.js).
        sans: ['"Inter Variable"', 'Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
