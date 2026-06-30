import React from 'react';
import { getPageBatch } from '../utils/pageBatch';

const Pagination = ({ currentPage, totalPages, onPageChange, totalItems, pageSize, label = 'items' }) => {
  if (!totalPages || totalPages <= 1) return null;

  const start = Math.min((currentPage - 1) * pageSize + 1, totalItems);
  const end   = Math.min(currentPage * pageSize, totalItems);

  const batch = getPageBatch(currentPage, totalPages);

  return (
    <div className="pg-container">
      <span className="pg-info">
        Showing {start}–{end} of {totalItems} {label}
      </span>
      <div className="pg-controls">
        <button
          type="button"
          className="pg-btn pg-btn--jump"
          disabled={!batch.hasPrevBatch}
          onClick={() => onPageChange(batch.prevBatchPage)}
          aria-label="Previous batch"
          title="Previous batch"
        >
          «
        </button>
        <button
          type="button"
          className="pg-btn"
          disabled={currentPage === 1}
          onClick={() => onPageChange(currentPage - 1)}
        >
          ← Prev
        </button>
        {batch.pages.map((page) => (
          <button
            key={page}
            type="button"
            className={`pg-btn${currentPage === page ? ' pg-btn--active' : ''}`}
            onClick={() => onPageChange(page)}
          >
            {page}
          </button>
        ))}
        <button
          type="button"
          className="pg-btn"
          disabled={currentPage === totalPages}
          onClick={() => onPageChange(currentPage + 1)}
        >
          Next →
        </button>
        <button
          type="button"
          className="pg-btn pg-btn--jump"
          disabled={!batch.hasNextBatch}
          onClick={() => onPageChange(batch.nextBatchPage)}
          aria-label="Next batch"
          title="Next batch"
        >
          »
        </button>
      </div>
    </div>
  );
};

export default Pagination;
