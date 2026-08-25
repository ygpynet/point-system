const path = require('path');
const config = require('flarum-webpack-config');

const base = config();

module.exports = {
  ...base,
  entry: {
    forum: './js/src/forum/index.tsx',
    admin: './js/src/admin/index.tsx',
  },
  output: {
    ...base.output,
    path: path.resolve(__dirname, 'js/dist'),
  },
};
