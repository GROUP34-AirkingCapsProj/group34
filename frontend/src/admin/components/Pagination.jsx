import React from 'react';

const Pagination = ({ currentPage, totalPages, onPageChange, totalItems, pageSize, label = 'items' }) => {
  if (!totalPages || totalPages <= 1) return null;

  const start = Math.min((currentPage - 1) * pageSize + 1, totalItems);
  const end   = Math.min(currentPage * pageSize, totalItems);

  const getPageNumbers = () => {
    const pages = [];
    const delta = 2;
    for (let i = 1; i <= totalPages; i++) {
      if (i === 1 || i === totalPages || (i >= currentPage - delta && i <= currentPage + delta)) {
        pages.push(i);
      } else if (pages[pages.length - 1] !== '...') {
        pages.push('...');
      }
    }
    return pages;
  };

  return (
    <div className="pg-container">
      <span className="pg-info">
        Showing {start}–{end} of {totalItems} {label}
      </span>
      <div className="pg-controls">
        <button
          type="button"
          className="pg-btn"
          disabled={currentPage === 1}
          onClick={() => onPageChange(currentPage - 1)}
        >
          ← Prev
        </button>
        {getPageNumbers().map((page, idx) =>
          page === '...' ? (
            <span key={`e-${idx}`} className="pg-ellipsis">…</span>
          ) : (
            <button
              key={page}
              type="button"
              className={`pg-btn${currentPage === page ? ' pg-btn--active' : ''}`}
              onClick={() => onPageChange(page)}
            >
              {page}
            </button>
          )
        )}
        <button
          type="button"
          className="pg-btn"
          disabled={currentPage === totalPages}
          onClick={() => onPageChange(currentPage + 1)}
        >
          Next →
        </button>
      </div>
    </div>
  );
};

export default Pagination;
