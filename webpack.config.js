const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = {
    entry: './src/admin.js',
    output: {
        path: path.resolve(__dirname, 'js'),
        filename: 'admin.js'
    },
    devtool: 'source-map', // ← IMPORTANTE: usar source-map em vez de eval
    module: {
        rules: [
            {
                test: /\.vue$/,
                loader: 'vue-loader'
            },
            {
                test: /\.css$/,
                use: ['vue-style-loader', 'css-loader']
            },
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@babel/preset-env']
                    }
                }
            }
        ]
    },
    plugins: [
        new VueLoaderPlugin()
    ],
    resolve: {
        alias: {
            'vue$': 'vue/dist/vue.esm.js'
        },
        extensions: ['*', '.js', '.vue', '.json']
    },
    mode: 'development', // ou 'production'
    performance: {
        hints: false // Remove os warnings de performance
    }
}
