import {render} from 'preact';

export default async () => {
  render(<Extension />, document.body);
};

function Extension() {
  return (
    <s-tile
      heading="Repairs"
      subheading="Book · Deposit · Balance"
      onClick={() => shopify.action.presentModal()}
    />
  );
}
