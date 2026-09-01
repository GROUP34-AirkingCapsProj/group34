import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import CountEntryPanel from './CountEntryPanel';

const packageLine = {
  po_detail_id: 1,
  product_name: 'Copper Tubing',
  count_unit: 'package',
  package_unit: 'Box',
  pieces_per_package: 50,
  ordered_pieces: 400,
  already_received: 100, // remaining = 300
};

const pieceLine = {
  po_detail_id: 2,
  product_name: 'Mounting Bolts',
  count_unit: 'piece',
  package_unit: null,
  pieces_per_package: 50, // deliberately present -- must NOT be used to multiply
  ordered_pieces: 40,
  already_received: 10, // remaining = 30
};

test('a package-counted line asks for the package unit and shows the live piece conversion', async () => {
  render(<CountEntryPanel line={packageLine} onSubmit={jest.fn()} />);

  expect(screen.getByLabelText(/how many boxes\?/i)).toBeInTheDocument();

  await userEvent.type(screen.getByLabelText(/how many boxes\?/i), '6');

  expect(screen.getByText('= 300 pcs')).toBeInTheDocument();
});

test('a piece-counted line asks for pieces and does not multiply by pieces_per_package', async () => {
  render(<CountEntryPanel line={pieceLine} onSubmit={jest.fn()} />);

  expect(screen.getByLabelText(/how many pieces\?/i)).toBeInTheDocument();

  await userEvent.type(screen.getByLabelText(/how many pieces\?/i), '5');

  // If this were wrongly multiplied by pieces_per_package (50) it would read "= 250 pcs".
  expect(screen.getByText('= 5 pcs')).toBeInTheDocument();
  expect(screen.queryByText('= 250 pcs')).not.toBeInTheDocument();
});

test('shows pieces remaining on the line', () => {
  render(<CountEntryPanel line={packageLine} onSubmit={jest.fn()} />);

  expect(screen.getByText(/300 pcs remaining on this line/i)).toBeInTheDocument();
});

test('onSubmit receives the units entered, not the converted piece total', async () => {
  const onSubmit = jest.fn();
  render(<CountEntryPanel line={packageLine} onSubmit={onSubmit} />);

  await userEvent.type(screen.getByLabelText(/how many boxes\?/i), '6');
  await userEvent.click(screen.getByRole('button', { name: /submit/i }));

  // 6 boxes converts to 300 pcs -- onSubmit must see 6, not 300, as units,
  // plus the verified actual piece count.
  expect(onSubmit).toHaveBeenCalledWith({ units: 6, actualPieces: 300 });
});

test('an empty or zero count does not call onSubmit', async () => {
  const onSubmit = jest.fn();
  render(<CountEntryPanel line={packageLine} onSubmit={onSubmit} />);

  // Empty: submit button should be disabled.
  expect(screen.getByRole('button', { name: /submit/i })).toBeDisabled();

  await userEvent.type(screen.getByLabelText(/how many boxes\?/i), '0');
  expect(screen.getByRole('button', { name: /submit/i })).toBeDisabled();

  await userEvent.click(screen.getByRole('button', { name: /submit/i }));
  expect(onSubmit).not.toHaveBeenCalled();
});

test('an over-count shows a warning but still allows submission', async () => {
  const onSubmit = jest.fn();
  render(<CountEntryPanel line={packageLine} onSubmit={onSubmit} />);

  // 7 boxes * 50 pcs/box = 350 pcs, 50 pcs more than the 300 remaining.
  await userEvent.type(screen.getByLabelText(/how many boxes\?/i), '7');

  const warning = screen.getByRole('alert');
  expect(warning).toHaveTextContent(/50 pcs more than ordered/i);

  const submitBtn = screen.getByRole('button', { name: /submit/i });
  expect(submitBtn).not.toBeDisabled();

  await userEvent.click(submitBtn);
  expect(onSubmit).toHaveBeenCalledWith({ units: 7, actualPieces: 350 });
});

test('busy disables the submit control so a slow post cannot be double-submitted', () => {
  const onSubmit = jest.fn();
  render(<CountEntryPanel line={packageLine} onSubmit={onSubmit} busy />);

  expect(screen.getByRole('button', { name: /submit/i })).toBeDisabled();
  expect(screen.getByLabelText(/how many boxes\?/i)).toBeDisabled();
  expect(screen.getByLabelText(/actual pieces/i)).toBeDisabled();
});

describe('piece verification field', () => {
  test('a package-counted line shows a pre-filled pieces field that follows the package count until edited', async () => {
    render(<CountEntryPanel line={packageLine} onSubmit={jest.fn()} />);

    const packagesInput = screen.getByLabelText(/how many boxes\?/i);
    const piecesInput = screen.getByLabelText(/actual pieces/i);
    expect(piecesInput).toHaveAttribute('id', 'count-entry-pieces');

    await userEvent.type(packagesInput, '6');
    expect(piecesInput).toHaveValue(300);

    fireEvent.change(packagesInput, { target: { value: '4' } });
    expect(piecesInput).toHaveValue(200);

    // Now the clerk edits the pieces value directly -- it must stick even as
    // the package count keeps changing.
    fireEvent.change(piecesInput, { target: { value: '197' } });
    expect(piecesInput).toHaveValue(197);

    fireEvent.change(packagesInput, { target: { value: '5' } });
    expect(piecesInput).toHaveValue(197);
  });

  test('editing below the expectation shows a shortfall', async () => {
    render(<CountEntryPanel line={packageLine} onSubmit={jest.fn()} />);

    const packagesInput = screen.getByLabelText(/how many boxes\?/i);
    const piecesInput = screen.getByLabelText(/actual pieces/i);

    fireEvent.change(packagesInput, { target: { value: '6' } });
    fireEvent.change(piecesInput, { target: { value: '297' } });

    expect(screen.getByText(/3 pcs short of 300 expected/i)).toBeInTheDocument();
  });

  test('editing above the expectation shows a surplus', async () => {
    render(<CountEntryPanel line={packageLine} onSubmit={jest.fn()} />);

    const packagesInput = screen.getByLabelText(/how many boxes\?/i);
    const piecesInput = screen.getByLabelText(/actual pieces/i);

    fireEvent.change(packagesInput, { target: { value: '6' } });
    fireEvent.change(piecesInput, { target: { value: '305' } });

    expect(screen.getByText(/5 pcs more than expected/i)).toBeInTheDocument();
  });

  test('onSubmit receives both the units and the verified actual piece count', async () => {
    const onSubmit = jest.fn();
    render(<CountEntryPanel line={packageLine} onSubmit={onSubmit} />);

    const packagesInput = screen.getByLabelText(/how many boxes\?/i);
    const piecesInput = screen.getByLabelText(/actual pieces/i);

    fireEvent.change(packagesInput, { target: { value: '6' } });
    fireEvent.change(piecesInput, { target: { value: '297' } });

    await userEvent.click(screen.getByRole('button', { name: /submit/i }));

    expect(onSubmit).toHaveBeenCalledWith({ units: 6, actualPieces: 297 });
  });

  test('a piece-counted line shows no second field', () => {
    render(<CountEntryPanel line={pieceLine} onSubmit={jest.fn()} />);

    expect(screen.queryByLabelText(/actual pieces/i)).not.toBeInTheDocument();
    expect(document.getElementById('count-entry-pieces')).toBeNull();
  });
});

describe('package unit pluralization', () => {
  test('"Box" pluralizes to "Boxes" (sh/ch/s/x/z branch)', () => {
    render(<CountEntryPanel line={{ ...packageLine, package_unit: 'Box' }} onSubmit={jest.fn()} />);
    expect(screen.getByLabelText(/how many boxes\?/i)).toBeInTheDocument();
  });

  test('"Roll" pluralizes to "Rolls" (plain +s branch)', () => {
    render(<CountEntryPanel line={{ ...packageLine, package_unit: 'Roll' }} onSubmit={jest.fn()} />);
    expect(screen.getByLabelText(/how many rolls\?/i)).toBeInTheDocument();
  });
});

describe('pieces_per_package floors at 1, matching Product::getPiecesPerPackage on the backend', () => {
  test('missing pieces_per_package: entering 6 shows 6 pcs, not 0', async () => {
    const onSubmit = jest.fn();
    const line = { ...packageLine };
    delete line.pieces_per_package;
    render(<CountEntryPanel line={line} onSubmit={onSubmit} />);

    await userEvent.type(screen.getByLabelText(/how many boxes\?/i), '6');
    expect(screen.getByText('= 6 pcs')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /submit/i }));
    expect(onSubmit).toHaveBeenCalledWith({ units: 6, actualPieces: 6 });
  });

  test('pieces_per_package of 0: entering 6 shows 6 pcs, not 0', async () => {
    const onSubmit = jest.fn();
    render(
      <CountEntryPanel line={{ ...packageLine, pieces_per_package: 0 }} onSubmit={onSubmit} />
    );

    await userEvent.type(screen.getByLabelText(/how many boxes\?/i), '6');
    expect(screen.getByText('= 6 pcs')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /submit/i }));
    expect(onSubmit).toHaveBeenCalledWith({ units: 6, actualPieces: 6 });
  });
});
