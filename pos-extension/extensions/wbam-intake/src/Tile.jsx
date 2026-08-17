import {render} from 'preact';

export default async () => {
  render(<Extension />, document.body);
};

function Extension() {
  return (
    <s-tile
      heading="Trade In Device Intake"
      subheading="Buy-in · Trade-in · Custom"
      onClick={() => shopify.action.presentModal()}
    />
  );
}
