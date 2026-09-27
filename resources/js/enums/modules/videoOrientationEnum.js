// 0 = Auto: Facebook videos and any Shorts/reel link play vertical, the rest
// wide (see App\Support\VideoEmbed::isPortrait).
const videoOrientationEnum = Object.freeze([
    {
        id: 0,
        name: "Auto"
    },
    {
        id: 5,
        name: "Landscape (16:9)"
    },
    {
        id: 10,
        name: "Vertical / Reel (9:16)"
    },
]);
export default videoOrientationEnum;
