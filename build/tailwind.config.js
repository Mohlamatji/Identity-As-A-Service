/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    '../app/Views/**/*.php',
    '../public/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        ink: '#0E1526',
        panel: '#151E33',
        line: '#28324A',
        gold: '#D9A441',
        teal: '#4C9A8E',
        danger: '#C4544B',
        mute: '#8A93A6',
      },
      fontFamily: {
        sans: ['"IBM Plex Sans"', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'monospace'],
      },
    },
  },
  plugins: [],
};
