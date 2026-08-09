/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js}'],
  theme: {
    extend: {
      colors: {
        brand: {
          950: '#031A3E',
          900: '#06234E',
          800: '#083264',
          700: '#0A437F',
          600: '#0862C6',
          500: '#1675DD',
          400: '#438FE5',
          300: '#7EB2EE',
          200: '#B7D4F6',
          100: '#DFEDFC',
          50: '#F2F8FE',
        },
        primary: {
          DEFAULT: '#0862C6',
          hover: '#0754A9',
          active: '#06478F',
          subtle: '#E7F1FC',
          foreground: '#FEFEFE',
        },
        accent: {
          DEFAULT: '#F1A000',
          hover: '#D98F00',
          active: '#BE7D00',
          subtle: '#FFF4D6',
          foreground: '#031A3E',
        },
        surface: {
          DEFAULT: '#FEFEFE',
          subtle: '#F7F9FC',
          muted: '#F0F3F7',
          raised: '#FFFFFF',
          inverse: '#031A3E',
        },
        ink: {
          DEFAULT: '#031A3E',
          strong: '#031A3E',
          muted: '#526176',
          subtle: '#7B8798',
          inverse: '#FEFEFE',
        },
        border: {
          DEFAULT: '#DDE3EA',
          strong: '#C5CDD8',
          subtle: '#E9EDF2',
          focus: '#0862C6',
        },
        success: {
          DEFAULT: '#16804A',
          strong: '#11663B',
          subtle: '#E5F5ED',
          foreground: '#FFFFFF',
        },
        warning: {
          DEFAULT: '#F1A000',
          strong: '#B97900',
          subtle: '#FFF4D6',
          foreground: '#3D2900',
        },
        danger: {
          DEFAULT: '#D63C3C',
          strong: '#B52B2B',
          subtle: '#FCE8E8',
          foreground: '#FFFFFF',
        },
        info: {
          DEFAULT: '#0862C6',
          strong: '#064B99',
          subtle: '#E7F1FC',
          foreground: '#FFFFFF',
        },
        maintenance: {
          ok: '#16804A',
          due: '#F1A000',
          overdue: '#D63C3C',
          scheduled: '#0862C6',
          inactive: '#7B8798',
        },
      },
      boxShadow: {
        card: '0 1px 2px rgba(3, 26, 62, 0.04), 0 8px 24px rgba(3, 26, 62, 0.05)',
        sidebar: '8px 0 24px rgba(3, 26, 62, 0.12)',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
